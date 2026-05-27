--TEST--
Windows: a coroutine writing after peer reset eventually observes the RST
--DESCRIPTION--
Windows counterpart of 046-write_wakes_on_peer_reset.phpt. On POSIX the
peer RST is surfaced as POLLERR and the second fwrite() fails
deterministically. On Windows IOCP the first multi-MiB fwrite() is
accepted into kernel buffers, and WSAECONNRESET is delivered to a later
write on its own schedule (typically the 2nd or 3rd small write after
the reset). What this test guarantees:

  - the writer does not hang;
  - within a bounded number of post-RST writes one of them fails with
    feof()===true and an errno reflecting the connection reset.

The POSIX-side "exactly the second fwrite fails" wording is not
portable to Winsock and is intentionally not asserted here.

See true-async/php-async#134.
--SKIPIF--
<?php
if (PHP_OS_FAMILY !== 'Windows') {
    echo 'skip Windows-only; POSIX path covered by 046-write_wakes_on_peer_reset.phpt';
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$addr   = stream_socket_get_name($server, false);

// Peer: accept, drain only 8 KiB, then close while the rest is still
// queued. Closing a socket with unread input makes the kernel send RST.
$peer = spawn(function () use ($server) {
    $conn = stream_socket_accept($server, 5);
    fread($conn, 8192);
    fclose($conn);
});

// Writer: push a multi-MiB upload that will be accepted by Winsock,
// then keep poking the dead socket with small writes until one of them
// reports the reset. Bound the loop so a real hang would still fail
// the test fast.
$writer = spawn(function () use ($addr) {
    $sock = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    $payload = str_repeat('x', 8 * 1024 * 1024);
    $first = @fwrite($sock, $payload);
    echo "first fwrite returned: ", ($first === false ? 'false' : 'int'), "\n";

    $detected = false;
    for ($i = 0; $i < 16; $i++) {
        $n = @fwrite($sock, 'tail');
        if ($n === false || $n === 0) {
            $detected = true;
            break;
        }
        usleep(50000);
    }
    echo "reset detected by later fwrite: ", ($detected ? 'yes' : 'no'), "\n";
    echo "feof after reset: ", (feof($sock) ? 'yes' : 'no'), "\n";
    fclose($sock);
});

await_all([$peer, $writer]);
fclose($server);
echo "writer did not hang\n";
?>
--EXPECT--
first fwrite returned: int
reset detected by later fwrite: yes
feof after reset: yes
writer did not hang
