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
use function Async\await_all;
use function Async\delay;

echo "Start\n";

// Array to collect output from spawn functions
$output = [];

// Shared variable for server port
$port = null;

// Server coroutine
$server = spawn(function() use (&$port, &$output) {
    $output[] = "Server: creating socket";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($socket, '127.0.0.1', 0);
    socket_listen($socket);
    
    $addr = '';
    socket_getsockname($socket, $addr, $port);
    $output[] = "Server: listening on port $port";
    
    $output[] = "Server: waiting for connection";
    $client = socket_accept($socket);
    $output[] = "Server: client connected";
    
    $data = socket_read($client, 1024);
    $output[] = "Server: received '$data'";
    
    socket_write($client, "Hello from server");
    $output[] = "Server: response sent";
    
    socket_close($client);
    socket_close($socket);
});

// Client coroutine
$client = spawn(function() use (&$port, &$output) {
    // Wait for the server to set the port
    while ($port === null) {
        delay(1);
    }
    
    $output[] = "Client: connecting";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!socket_connect($socket, '127.0.0.1', $port)) {
        $output[] = "Client: failed to connect";
        return;
    }
    
    socket_write($socket, "Hello from client");
    $output[] = "Client: sent request";
    
    $response = socket_read($socket, 1024);
    $output[] = "Client: received '$response'";
    
    socket_close($socket);
});

// Worker coroutine for parallel execution
$worker = spawn(function() use (&$output) {
    $output[] = "Worker: doing work while server waits";
});

await_all([$server, $client, $worker]);

// Sort and output results
sort($output);
foreach ($output as $line) {
    echo $line . "\n";
}

echo "End\n";

?>
--EXPECTF--
Start
Client: connecting
Client: received 'Hello from server'
Client: sent request
Server: client connected
Server: creating socket
Server: listening on port %d
Server: received 'Hello from client'
Server: response sent
Server: waiting for connection
Worker: doing work while server waits
End