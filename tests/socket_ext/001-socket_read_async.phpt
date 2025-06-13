--TEST--
socket_read() async operation
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

// Shared variable for server port
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
    
    echo "Server: waiting for connection\n";
    $client = socket_accept($socket);
    echo "Server: client connected\n";
    
    $data = socket_read($client, 1024);
    echo "Server: received '$data'\n";
    
    socket_write($client, "Hello from server");
    echo "Server: response sent\n";
    
    socket_close($client);
    socket_close($socket);
});

// Client coroutine
$client = spawn(function() use (&$port) {
    // Wait for the server to set the port
    while ($port === null) {
        delay(1);
    }
    
    echo "Client: connecting\n";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!socket_connect($socket, '127.0.0.1', $port)) {
        echo "Client: failed to connect\n";
        return;
    }
    
    socket_write($socket, "Hello from client");
    echo "Client: sent request\n";
    
    $response = socket_read($socket, 1024);
    echo "Client: received '$response'\n";
    
    socket_close($socket);
});

// Worker coroutine for parallel execution
$worker = spawn(function() {
    echo "Worker: doing work while server waits\n";
});

awaitAll([$server, $client, $worker]);
echo "End\n";

?>
--EXPECTF--
Start
Server: creating socket
Server: listening on port %d
Server: waiting for connection
Client: connecting
Worker: doing work while server waits
Server: client connected
Client: sent request
Server: received 'Hello from client'
Server: response sent
Client: received 'Hello from server'
End