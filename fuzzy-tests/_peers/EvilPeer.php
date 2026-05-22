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
 * Both modes call EvilPeer::serve() on the accepted connection — only the
 * surrounding accept/listen plumbing differs.
 *
 * Fault table keys:
 *   payload : string  — the bytes the peer would deliver
 *   slice   : int     — chunk size; 0 = whole payload in one write
 *   delay   : int     — ms to pause between chunks (drip/latency toxic)
 *   reset   : int     — byte offset at which the peer abruptly closes the
 *                       connection mid-stream; -1 = deliver the whole payload
 *
 * serve() records the exact low-level sequence it played out into the
 * Context event log, so a failing combined-chaos test shows precisely
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
        $payload = $spec['payload'] ?? '';
        $slice   = $spec['slice']   ?? 0;
        $delay   = $spec['delay']   ?? 0;
        $reset   = $spec['reset']   ?? -1;

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
                \Async\delay($delay);
                $trace[] = 'd' . $delay;
            }
        }

        // Close the connection. With a reset toxic this happens mid-stream —
        // the client must observe a clean truncation (its bytes are a prefix
        // of the payload) and terminate, never hang waiting for the rest.
        @fclose($conn);
        $closedAt = ($reset >= 0 && $reset < strlen($payload)) ? "reset@$len" : "close@$len";
        $trace[] = $closedAt;

        if ($ctx !== null) {
            $ctx->events[] = sprintf(
                'evil-peer %s: payload=%dB slice=%d delay=%d reset=%d | %s',
                $name, strlen($payload), $slice, $delay, $reset, implode(' ', $trace));
        }
    }
}
