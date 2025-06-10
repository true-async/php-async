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

// Server coroutine
$server = spawn(function() use (&$port) {
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
    echo "Server: client1 connected\n";
    
    $client2 = socket_accept($socket);
    echo "Server: client2 connected\n";
    
    socket_write($client1, "Hello client1");
    socket_write($client2, "Hello client2");
    
    socket_close($client1);
    socket_close($client2);
    socket_close($socket);
});

// Client coroutines
$client1 = spawn(function() use (&$port) {
    while ($port === null) {
        delay(1);
    }
    
    echo "Client1: connecting\n";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    
    if (socket_connect($socket, '127.0.0.1', $port)) {
        echo "Client1: connected successfully\n";
        $data = socket_read($socket, 1024);
        echo "Client1: received '$data'\n";
    } else {
        echo "Client1: connection failed\n";
    }
    
    socket_close($socket);
});

$client2 = spawn(function() use (&$port) {
    while ($port === null) {
        delay(1);
    }
    
    echo "Client2: connecting\n";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    
    if (socket_connect($socket, '127.0.0.1', $port)) {
        echo "Client2: connected successfully\n";
        $data = socket_read($socket, 1024);
        echo "Client2: received '$data'\n";
    } else {
        echo "Client2: connection failed\n";
    }
    
    socket_close($socket);
});

awaitAll([$server, $client1, $client2]);
echo "End\n";

?>
--EXPECTF--
Start
Server: creating socket
Server: listening on port %d
Server: accepting connections
Client1: connecting
Client1: connected successfully
Server: client1 connected
Client2: connecting
Client2: connected successfully
Server: client2 connected
Client1: received 'Hello client1'
Client2: received 'Hello client2'
End