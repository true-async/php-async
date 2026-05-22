--TEST--
A coroutine blocked in fwrite() wakes when the peer resets the connection
--DESCRIPTION--
Regression test. A writer suspended on a full send buffer (ASYNC_WRITABLE)
must be released when the peer abruptly closes the connection. libuv reports
the resulting POLLERR as UV_EBADF, which the reactor turns into a bare
ASYNC_DISCONNECT. A write proxy's mask is ASYNC_WRITABLE only, so without
treating a disconnect as terminal for every proxy the writer hangs forever.
Found by the fuzzy-tests io/backpressure chaos suite.
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$addr   = stream_socket_get_name($server, false);

// Peer: accept, drain only 8 KiB, then close while ~MiB are still queued.
// Closing a socket with unread input makes the kernel send an RST.
$peer = spawn(function () use ($server) {
    $conn = stream_socket_accept($server, 5);
    fread($conn, 8192);
    fclose($conn);
});

// Writer: a multi-MiB upload that cannot fit the loopback buffers, so the
// async fwrite() suspends on the write-wait hook. The peer's RST must wake it.
$writer = spawn(function () use ($addr) {
    $sock = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    $payload = str_repeat('x', 8 * 1024 * 1024);
    $n = @fwrite($sock, $payload);
    echo "first fwrite returned: ", ($n === false ? 'false' : 'int'), "\n";
    $n2 = @fwrite($sock, 'tail');
    echo "second fwrite failed: ", ($n2 === false || $n2 === 0 ? 'yes' : 'no'), "\n";
    fclose($sock);
});

await_all([$peer, $writer]);
fclose($server);
echo "writer did not hang\n";
?>
--EXPECT--
first fwrite returned: int
second fwrite failed: yes
writer did not hang
