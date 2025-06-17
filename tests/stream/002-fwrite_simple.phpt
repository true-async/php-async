--TEST--
Simple fwrite test with coroutine switching
--SKIPIF--
<?php
//This test only makes sense on Windows,
//since on UNIX systems the fwrite and fclose operations are asynchronous for sockets.
if (PHP_OS_FAMILY !== 'Windows') {
    die("skip Windows-only test\n");
}
?>
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';

use function Async\spawn;
use function Async\awaitAll;

echo "Start\n";

$sockets = create_socket_pair();
list($sock1, $sock2) = $sockets;

$writer = spawn(function() use ($sock1) {
    echo "Writer: writing data\n";
    fwrite($sock1, "test message");
    fclose($sock1);
    echo "Writer: done writing\n";
});

$worker = spawn(function() {
    echo "Worker: doing work\n";
    echo "Worker: finished work\n";
});

awaitAll([$writer, $worker]);

$data = fread($sock2, 1024);
echo "Read: '$data'\n";
fclose($sock2);

echo "End\n";

?>
--EXPECT--
Start
Writer: writing data
Worker: doing work
Worker: finished work
Writer: done writing
Read: 'test message'
End