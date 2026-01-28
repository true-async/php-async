--TEST--
socket_recv() and socket_send() async operations
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
    
    $client = socket_accept($socket);
    $output[] = "Server: client connected";
    
    $buffer = '';
    $bytes = socket_recv($client, $buffer, 1024, 0);
    $output[] = "Server: received $bytes bytes: '$buffer'";
    
    $sent = socket_send($client, "Response from server", 20, 0);
    $output[] = "Server: sent $sent bytes";
    
    socket_close($client);
    socket_close($socket);
});

// Client coroutine
$client = spawn(function() use (&$port, &$output) {
    while ($port === null) {
        delay(1);
    }
    
    $output[] = "Client: connecting";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($socket, '127.0.0.1', $port);
    
    $sent = socket_send($socket, "Request from client", 19, 0);
    $output[] = "Client: sent $sent bytes";
    
    $buffer = '';
    $bytes = socket_recv($socket, $buffer, 1024, 0);
    $output[] = "Client: received $bytes bytes: '$buffer'";
    
    socket_close($socket);
});

await_all([$server, $client]);

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
Client: connecting
Client: received 20 bytes: 'Response from server'
Client: sent 19 bytes
Server: client connected
Server: received 19 bytes: 'Request from client'
Server: sent 20 bytes
End