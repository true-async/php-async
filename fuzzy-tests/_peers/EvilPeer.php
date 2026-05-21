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
 */

namespace Async\Chaos;

final class EvilPeer {
    /**
     * Apply the fault table to one accepted connection, then close it.
     *
     * @param resource $conn an accepted stream-socket connection
     * @param array{payload:string,slice:int,delay:int} $spec
     */
    public static function serve($conn, array $spec): void {
        $payload = $spec['payload'] ?? '';
        $slice   = $spec['slice']   ?? 0;
        $delay   = $spec['delay']   ?? 0;

        $len = strlen($payload);
        $step = $slice > 0 ? $slice : ($len > 0 ? $len : 1);

        for ($off = 0; $off < $len; $off += $step) {
            $chunk = substr($payload, $off, $step);
            @fwrite($conn, $chunk);
            if ($delay > 0 && $off + $step < $len) {
                \Async\delay($delay);
            }
        }

        // Graceful close — the client sees EOF once every chunk is delivered.
        @fclose($conn);
    }
}
