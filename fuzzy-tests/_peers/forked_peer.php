<?php
/**
 * forked_peer.php — runs an EvilPeer in a separate OS process.
 *
 * Driven by Context::run() when a peer is declared with the "runs as a
 * forked peer" step. Unlike the in-process peer (a coroutine sharing the
 * test's reactor), this is a genuinely independent process with its own
 * TCP stack endpoint — the client faces a real external peer.
 *
 * Protocol with the parent:
 *   - the fault table (a plain array) arrives on STDIN as base64(serialize());
 *   - the bound "host:port" is printed to STDOUT as the first line, so the
 *     parent learns where to connect before it starts any client coroutine;
 *   - the process accepts exactly one connection, plays out the fault table
 *     with blocking I/O, then exits.
 */

require __DIR__ . '/EvilPeer.php';

use Async\Chaos\EvilPeer;

$raw  = stream_get_contents(STDIN);
$spec = @unserialize(base64_decode((string) $raw));
if (!is_array($spec)) {
    fwrite(STDERR, "forked_peer: bad spec\n");
    exit(2);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "forked_peer: cannot listen: $errstr\n");
    exit(3);
}

// Hand the bound address to the parent, then accept one connection.
echo stream_socket_get_name($server, false), "\n";
fflush(STDOUT);

$conn = @stream_socket_accept($server, 10);
if ($conn !== false) {
    if (($spec['mode'] ?? 'serve') === 'consume') {
        EvilPeer::consume($conn, $spec);
    } else {
        EvilPeer::serve($conn, $spec);
    }
}

@fclose($server);
exit(0);
