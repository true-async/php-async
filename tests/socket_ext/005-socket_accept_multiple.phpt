--TEST--
socket_accept() with multiple concurrent connections
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
    socket_listen($socket, 5);
    
    $addr = '';
    socket_getsockname($socket, $addr, $port);
    echo "Server: listening on port $port\n";
    
    $clients = [];
    
    // Accept 3 clients
    for ($i = 1; $i <= 3; $i++) {
        echo "Server: waiting for client $i\n";
        $client = socket_accept($socket);
        echo "Server: client $i connected\n";
        $clients[] = $client;
    }
    
    // Send responses to all clients
    foreach ($clients as $i => $client) {
        $clientNum = $i + 1;
        socket_write($client, "Response to client $clientNum");
        socket_close($client);
    }
    
    socket_close($socket);
});

// Multiple client coroutines
$clients = [];
for ($i = 1; $i <= 3; $i++) {
    $clients[] = spawn(function() use (&$port, $i) {
        while ($port === null) {
            delay(1);
        }
        
        // Small delay to stagger connections
        delay($i);
        
        echo "Client$i: connecting\n";
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if (socket_connect($socket, '127.0.0.1', $port)) {
            echo "Client$i: connected\n";
            $data = socket_read($socket, 1024);
            echo "Client$i: received '$data'\n";
        }
        
        socket_close($socket);
    });
}

awaitAll(array_merge([$server], $clients));
echo "End\n";

?>
--EXPECTF--
Start
Server: creating socket
Server: listening on port %d
Server: waiting for client 1
Client1: connecting
%a