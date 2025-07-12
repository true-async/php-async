--TEST--
socket_connect() async operation
--SKIPIF--
<?php
if (!extension_loaded('sockets')) {
    die('skip sockets extension not available');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

echo "Start\n";

$port = null;
$output = [];

// Server coroutine
$server = spawn(function() use (&$port, &$output) {
    echo "Server: creating socket\n";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($socket, '127.0.0.1', 0);
    socket_listen($socket);
    
    $addr = '';
    socket_getsockname($socket, $addr, $port);
    echo "Server: listening on port $port\n";
    
    echo "Server: accepting connections\n";
    $client1 = socket_accept($socket);
    $output[] = "Server: client1 connected";
    
    $client2 = socket_accept($socket);
    $output[] = "Server: client2 connected";
    
    socket_write($client1, "Hello client1");
    socket_write($client2, "Hello client2");
    
    socket_close($client1);
    socket_close($client2);
    socket_close($socket);
});

// Client coroutines
$client1 = spawn(function() use (&$port, &$output) {
    while ($port === null) {
        delay(1);
    }
    
    $output[] = "Client1: connecting";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    
    if (socket_connect($socket, '127.0.0.1', $port)) {
        $output[] = "Client1: connected successfully";
        $data = socket_read($socket, 1024);
        $output[] = "Client1: received '$data'";
    } else {
        $output[] = "Client1: connection failed";
    }
    
    socket_close($socket);
});

$client2 = spawn(function() use (&$port, &$output) {
    while ($port === null) {
        delay(1);
    }

    $output[] = "Client2: connecting";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    if (socket_connect($socket, '127.0.0.1', $port)) {
        $output[] = "Client2: connected successfully";
        $data = socket_read($socket, 1024);
        $output[] = "Client2: received '$data'";
    } else {
        $output[] = "Client2: connection failed";
    }
    
    socket_close($socket);
});

awaitAll([$server, $client1, $client2]);

// Sort output for deterministic results
sort($output);
foreach ($output as $message) {
    echo $message . "\n";
}

echo "End\n";

?>
--EXPECTF--
Start
Server: creating socket
Server: listening on port %d
Server: accepting connections
Client1: connected successfully
Client1: connecting
Client1: received 'Hello client1'
Client2: connected successfully
Client2: connecting
Client2: received 'Hello client2'
Server: client1 connected
Server: client2 connected
End