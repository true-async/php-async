--TEST--
Stream: stream_socket_accept() + timeout
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
use function Async\suspend;

echo "Start\n";

$server_port = null;

// Server coroutine
$server = spawn(function() {
    echo "Server: starting\n";

    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$socket) {
        echo "Server: failed to start - $errstr\n";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    $server_port = (int)substr($address, strrpos($address, ':') + 1);
    echo "Server: listening on port $server_port\n";

    echo "Server: accepting connections\n";
    $client = stream_socket_accept($socket, 1);

    echo "Server end\n";
});

echo "End\n";

?>
--EXPECTF--
Start
End
Server: starting
Server: listening on port %d
Server: accepting connections

Warning: stream_socket_accept(): Accept failed: %s
Server end
