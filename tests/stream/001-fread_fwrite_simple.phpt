--TEST--
Simple fread/fwrite test with two coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

echo "Start\n";

$sockets = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
list($sock1, $sock2) = $sockets;

$writer = spawn(function() use ($sock1) {
    echo "Writer: about to write\n";
    fwrite($sock1, "hello");
    echo "Writer: wrote data\n";
    fclose($sock1);
});

$reader = spawn(function() use ($sock2) {
    echo "Reader: about to read\n";
    $data = fread($sock2, 5);
    echo "Reader: read '$data'\n";
    fclose($sock2);
});

awaitAll([$writer, $reader]);
echo "End\n";

?>
--EXPECT--
Start
Writer: about to write
Reader: about to read
Writer: wrote data
Reader: read 'hello'
End