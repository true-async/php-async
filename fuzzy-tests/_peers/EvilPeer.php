<?php
/**
 * EvilPeer — a deliberately misbehaving network peer for I/O chaos tests.
 *
 * The peer is driven by a declarative fault table (see Context::evilPeerDefs):
 * it accepts one connection and delivers its payload through the configured
 * toxics. The same EvilPeer runs in two modes:
 *
 *   - in-process: a coroutine inside the test process (default), or
 *   - forked:     a separate php process (a `... as forked peer` step).
 *
 * Both modes call serve() or consume() on the accepted connection — only
 * the surrounding accept/listen plumbing differs.
 *
 * A peer also has a direction, selected by the `mode` fault-table key:
 *
 *   - serve   (default): the peer writes its payload to the client. This is
 *               the download path — the client reassembles the byte stream.
 *   - consume:  the peer reads from the client (slowly, or not at all). This
 *               is the upload / back-pressure path — a peer that drains its
 *               receive buffer slowly makes the client's fwrite() block on a
 *               full send buffer, exercising the reactor's write-wait hook.
 *   - http:     the peer drains one HTTP request, then writes back an HTTP/1.1
 *               response — status line + headers + body. The same serve-mode
 *               toxics apply to the body (slice/delay/reset/hardReset); on top
 *               of them sit HTTP-specific toxics (see below). This is the path
 *               an async ext/curl client exercises through the reactor.
 *
 * HTTP-mode fault table keys (in addition to the serve keys above, which
 * apply to the response body):
 *   httpStatus      : int  — response status code (default 200)
 *   httpChunked     : bool — deliver the body with Transfer-Encoding: chunked
 *                            instead of a fixed Content-Length
 *   httpClenLie     : int  — delta added to the Content-Length header; a
 *                            positive value over-promises (curl waits for
 *                            bytes that never come → CURLE_PARTIAL_FILE), a
 *                            negative one under-promises (curl stops early →
 *                            a clean prefix). 0 = honest header.
 *   httpHeaderDelay : int  — ms to pause partway through the header block,
 *                            so the response headers dribble in (slow-headers
 *                            toxic). 0 = headers sent in one write.
 *
 * Fault table keys (serve mode):
 *   payload : string  — the bytes the peer would deliver
 *   slice   : int     — chunk size; 0 = whole payload in one write
 *   delay   : int     — ms to pause between chunks (drip/latency toxic)
 *   reset   : int     — byte offset at which the peer abruptly closes the
 *                       connection mid-stream; -1 = deliver the whole payload
 *
 * In consume mode the keys are reinterpreted as a read schedule:
 *   slice   : int     — bytes per read; 0 = never read at all (pure stall)
 *   delay   : int     — ms to pause between reads (slow-drain toxic)
 *   reset   : int     — stop reading and close after N bytes; -1 = read to EOF
 *   hold    : int     — ms to keep a never-read (slice==0) connection open
 *                       before closing it
 *
 * One key applies to both modes:
 *   hardReset : bool  — arm SO_LINGER{l_onoff:1,l_linger:0} before close so it
 *                       is a guaranteed RST (immediate, buffers discarded)
 *                       rather than a graceful FIN. Needs ext/sockets.
 *
 * serve()/consume() record the exact low-level sequence they played out into
 * the Context event log, so a failing combined-chaos test shows precisely
 * which toxics fired, with which (seeded-random-resolved) parameters.
 */

namespace Async\Chaos;

final class EvilPeer {
    /**
     * Apply the fault table to one accepted connection, then close it.
     *
     * @param resource     $conn an accepted stream-socket connection
     * @param array{payload:string,slice:int,delay:int,reset:int} $spec
     * @param Context|null $ctx  scenario context for the chaos event log
     * @param string       $name peer name, used in the log line
     */
    public static function serve($conn, array $spec, ?Context $ctx = null, string $name = 'peer'): void {
        $payload   = $spec['payload']   ?? '';
        $slice     = $spec['slice']     ?? 0;
        $delay     = $spec['delay']     ?? 0;
        $reset     = $spec['reset']     ?? -1;
        $hardReset = $spec['hardReset'] ?? false;
        $forked    = $spec['forked']    ?? false;

        // A reset toxic caps delivery at `reset` bytes; otherwise deliver all.
        $len = strlen($payload);
        if ($reset >= 0 && $reset < $len) {
            $len = $reset;
        }
        $step = $slice > 0 ? $slice : ($len > 0 ? $len : 1);

        // Compact trace of the low-level operations, e.g. "w48 d2 w48 ...".
        $trace = [];
        for ($off = 0; $off < $len; $off += $step) {
            // Clamp the final chunk so a reset toxic delivers exactly `len`.
            $n = min($step, $len - $off);
            @fwrite($conn, substr($payload, $off, $n));
            $trace[] = 'w' . $n;
            if ($delay > 0 && $off + $step < $len) {
                self::pause($delay, $forked);
                $trace[] = 'd' . $delay;
            }
        }

        // Close the connection. With a reset toxic this happens mid-stream —
        // the client must observe a clean truncation (its bytes are a prefix
        // of the payload) and terminate, never hang waiting for the rest.
        // A hard-reset toxic forces an RST instead of a graceful FIN.
        $resetSock = $hardReset ? self::armHardReset($conn) : null;
        @fclose($conn);
        $closedAt = ($reset >= 0 && $reset < strlen($payload)) ? "reset@$len" : "close@$len";
        $trace[] = $hardReset ? "hard-$closedAt" : $closedAt;

        if ($ctx !== null) {
            $ctx->events[] = sprintf(
                'evil-peer %s: payload=%dB slice=%d delay=%d reset=%d hardReset=%d | %s',
                $name, strlen($payload), $slice, $delay, $reset, (int) $hardReset, implode(' ', $trace));
        }
    }

    /**
     * Pause between chunks/reads. An in-process peer must yield to the shared
     * reactor (\Async\delay); a forked peer is its own process with no shared
     * event loop, so a plain blocking usleep() is correct and simpler.
     */
    private static function pause(int $ms, bool $forked): void {
        if ($forked) {
            usleep($ms * 1000);
        } else {
            \Async\delay($ms);
        }
    }

    /**
     * Arm SO_LINGER{l_onoff:1,l_linger:0} on an accepted connection so the
     * next close() emits an immediate RST instead of a graceful FIN. The
     * returned Socket shares the underlying fd — the caller must keep it
     * referenced until after the close so the option stays applied.
     *
     * @param resource $conn
     * @return \Socket|null
     */
    private static function armHardReset($conn): ?\Socket {
        $sock = @socket_import_stream($conn);
        if ($sock instanceof \Socket) {
            @socket_set_option($sock, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
            return $sock;
        }
        return null;
    }

    /**
     * Consume-mode: read from the accepted connection per the fault table,
     * then close it. A slow or never-reading peer is what makes the client's
     * fwrite() block on a full send buffer — the back-pressure path.
     *
     * @param resource     $conn an accepted stream-socket connection
     * @param array{slice:int,delay:int,reset:int,hold:int} $spec
     * @param Context|null $ctx  scenario context for the chaos event log
     * @param string       $name peer name, used in the log line
     */
    public static function consume($conn, array $spec, ?Context $ctx = null, string $name = 'peer'): void {
        $rate      = $spec['slice']     ?? 0;
        $delay     = $spec['delay']     ?? 0;
        $reset     = $spec['reset']     ?? -1;
        $hold      = $spec['hold']      ?? 0;
        $hardReset = $spec['hardReset'] ?? false;
        $forked    = $spec['forked']    ?? false;

        $trace = [];
        $total = 0;

        if ($rate <= 0) {
            // Pure stall: never read a byte. The client's send buffer fills
            // and its fwrite() suspends. Hold the connection open so a killer
            // coroutine has a window to cancel the blocked writer, then close.
            if ($hold > 0) {
                self::pause($hold, $forked);
                $trace[] = 'h' . $hold;
            }
            $trace[] = 'noread';
        } else {
            while (true) {
                // A reset toxic caps how much the peer drains, then abandons.
                if ($reset >= 0 && $total >= $reset) {
                    $trace[] = 'reset@' . $total;
                    break;
                }
                $want = $reset >= 0 ? min($rate, $reset - $total) : $rate;
                $chunk = @fread($conn, $want);
                if ($chunk === false || $chunk === '') {
                    $trace[] = 'eof@' . $total;
                    break;
                }
                $total += strlen($chunk);
                $trace[] = 'r' . strlen($chunk);
                if (@feof($conn)) {
                    $trace[] = 'eof@' . $total;
                    break;
                }
                if ($delay > 0) {
                    self::pause($delay, $forked);
                    $trace[] = 'd' . $delay;
                }
            }
        }

        // A hard-reset toxic forces an RST instead of a graceful FIN.
        $resetSock = $hardReset ? self::armHardReset($conn) : null;
        @fclose($conn);
        $trace[] = $hardReset ? 'hard-close' : 'close';

        if ($ctx !== null) {
            $ctx->inc("evil_peer_read_bytes_$name", $total);
            $ctx->events[] = sprintf(
                'evil-peer %s [consume]: rate=%d delay=%d reset=%d hold=%d hardReset=%d read=%dB | %s',
                $name, $rate, $delay, $reset, $hold, (int) $hardReset, $total, implode(' ', $trace));
        }
    }

    /**
     * HTTP-mode: drain one HTTP request off the accepted connection, then play
     * out an HTTP/1.1 response through the configured toxics. An async ext/curl
     * client driven by the reactor faces this peer; the body toxics are exactly
     * the serve-mode ones (slice/delay/reset/hardReset), with the HTTP-specific
     * toxics layered on top (see the class docblock).
     *
     * @param resource     $conn an accepted stream-socket connection
     * @param array        $spec the fault table
     * @param Context|null $ctx  scenario context for the chaos event log
     * @param string       $name peer name, used in the log line
     */
    public static function serveHttp($conn, array $spec, ?Context $ctx = null, string $name = 'peer'): void {
        $body      = $spec['payload']         ?? '';
        $slice     = $spec['slice']           ?? 0;
        $delay     = $spec['delay']           ?? 0;
        $reset     = $spec['reset']           ?? -1;
        $hardReset = $spec['hardReset']       ?? false;
        $forked    = $spec['forked']          ?? false;
        $status    = $spec['httpStatus']      ?? 200;
        $chunked   = $spec['httpChunked']     ?? false;
        $clenLie   = $spec['httpClenLie']     ?? 0;
        $hdrDelay  = $spec['httpHeaderDelay']  ?? 0;

        $trace = [];

        // Drain the request — request line + headers, then a body if the
        // client announced a Content-Length (so a POST does not leave bytes
        // unread, which a hard close would otherwise turn into an RST).
        $req = '';
        while (strpos($req, "\r\n\r\n") === false && strlen($req) < 65536) {
            $chunk = @fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $req .= $chunk;
        }
        if (preg_match('/Content-Length:\s*(\d+)/i', $req, $m)) {
            $want = (int) $m[1];
            $have = strlen($req) - (int) strpos($req, "\r\n\r\n") - 4;
            while ($have < $want) {
                $chunk = @fread($conn, min(4096, $want - $have));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $have += strlen($chunk);
            }
        }
        $trace[] = 'req' . strlen($req) . 'B';

        // Status line + headers. Either a (possibly mendacious) Content-Length
        // or chunked framing — never both.
        $head  = "HTTP/1.1 $status " . self::reason($status) . "\r\n";
        $head .= "Content-Type: application/octet-stream\r\n";
        if ($chunked) {
            $head .= "Transfer-Encoding: chunked\r\n";
        } else {
            $head .= 'Content-Length: ' . max(0, strlen($body) + $clenLie) . "\r\n";
        }
        $head .= "Connection: close\r\n\r\n";

        // Slow-headers toxic: split the header block and pause in the middle,
        // so the response status/headers dribble in over two TCP writes.
        if ($hdrDelay > 0 && strlen($head) > 2) {
            $cut = intdiv(strlen($head), 2);
            @fwrite($conn, substr($head, 0, $cut));
            self::pause($hdrDelay, $forked);
            @fwrite($conn, substr($head, $cut));
            $trace[] = 'hdr-split d' . $hdrDelay;
        } else {
            @fwrite($conn, $head);
            $trace[] = 'hdr';
        }

        // Body. A reset toxic caps delivery at `reset` bytes; otherwise the
        // whole body goes out, optionally sliced/dripped and chunk-framed.
        $len = strlen($body);
        if ($reset >= 0 && $reset < $len) {
            $len = $reset;
        }
        $step = $slice > 0 ? $slice : ($len > 0 ? $len : 1);
        for ($off = 0; $off < $len; $off += $step) {
            $n = min($step, $len - $off);
            $piece = substr($body, $off, $n);
            @fwrite($conn, $chunked ? sprintf("%x\r\n%s\r\n", $n, $piece) : $piece);
            $trace[] = 'w' . $n;
            if ($delay > 0 && $off + $step < $len) {
                self::pause($delay, $forked);
                $trace[] = 'd' . $delay;
            }
        }
        // The chunked terminator only goes out on an untruncated response — a
        // reset toxic closes mid-body, so the client must see a partial file.
        $truncated = ($reset >= 0 && $reset < strlen($body));
        if ($chunked && !$truncated) {
            @fwrite($conn, "0\r\n\r\n");
            $trace[] = 'chunk-end';
        }

        // Close — a hard-reset toxic forces an RST instead of a graceful FIN.
        $resetSock = $hardReset ? self::armHardReset($conn) : null;
        @fclose($conn);
        $trace[] = $hardReset ? "hard-close@$len" : "close@$len";

        if ($ctx !== null) {
            $ctx->events[] = sprintf(
                'evil-http %s: status=%d body=%dB chunked=%d clenLie=%d slice=%d delay=%d reset=%d hdrDelay=%d | %s',
                $name, $status, strlen($body), (int) $chunked, $clenLie,
                $slice, $delay, $reset, $hdrDelay, implode(' ', $trace));
        }
    }

    /** Reason phrase for the HTTP status codes the chaos suite uses. */
    private static function reason(int $status): string {
        return [
            200 => 'OK',          204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found',
            400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found',
            418 => "I'm a teapot",
            500 => 'Internal Server Error', 502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ][$status] ?? 'Status';
    }
}
