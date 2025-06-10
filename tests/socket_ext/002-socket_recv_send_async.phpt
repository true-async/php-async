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
    
    $client = socket_accept($socket);
    echo "Server: client connected\n";
    
    $buffer = '';
    $bytes = socket_recv($client, $buffer, 1024, MSG_WAITALL);
    echo "Server: received $bytes bytes: '$buffer'\n";
    
    $sent = socket_send($client, "Response from server", 20, 0);
    echo "Server: sent $sent bytes\n";
    
    socket_close($client);
    socket_close($socket);
});

// Client coroutine
$client = spawn(function() use (&$port) {
    while ($port === null) {
        delay(1);
    }
    
    echo "Client: connecting\n";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($socket, '127.0.0.1', $port);
    
    $sent = socket_send($socket, "Request from client", 19, 0);
    echo "Client: sent $sent bytes\n";
    
    $buffer = '';
    $bytes = socket_recv($socket, $buffer, 1024, MSG_WAITALL);
    echo "Client: received $bytes bytes: '$buffer'\n";
    
    socket_close($socket);
});

awaitAll([$server, $client]);
echo "End\n";

?>
--EXPECTF--
Start
Server: creating socket
Server: listening on port %d
Client: connecting
Server: client connected
Client: sent 19 bytes
Server: received 19 bytes: 'Request from client'
Server: sent 20 bytes
Client: received 20 bytes: 'Response from server'
End