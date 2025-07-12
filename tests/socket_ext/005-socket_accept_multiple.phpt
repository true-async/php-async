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

// Array to collect output from spawn functions
$output = [];

$port = null;

// Server coroutine
$server = spawn(function() use (&$port, &$output) {
    $output[] = "Server: creating socket";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($socket, '127.0.0.1', 0);
    socket_listen($socket, 5);
    
    $addr = '';
    socket_getsockname($socket, $addr, $port);
    $output[] = "Server: listening on port $port";
    
    $clients = [];
    
    // Accept 3 clients
    for ($i = 1; $i <= 3; $i++) {
        $output[] = "Server: waiting for client $i";
        $client = socket_accept($socket);
        $output[] = "Server: client $i connected";
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
    $clients[] = spawn(function() use (&$port, $i, &$output) {
        while ($port === null) {
            delay(1);
        }
        
        // Small delay to stagger connections
        delay($i);
        
        $output[] = "Client$i: connecting";
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if (socket_connect($socket, '127.0.0.1', $port)) {
            $output[] = "Client$i: connected";
            $data = socket_read($socket, 1024);
            $output[] = "Client$i: received '$data'";
        }
        
        socket_close($socket);
    });
}

awaitAll(array_merge([$server], $clients));

// Sort and output results
sort($output);
foreach ($output as $line) {
    echo $line . "\n";
}

echo "End\n";

?>
--EXPECTF--
Start
Client1: connected
Client1: connecting
Client1: received 'Response to client 1'
Client2: connected
Client2: connecting
Client2: received 'Response to client 2'
Client3: connected
Client3: connecting
Client3: received 'Response to client 3'
Server: client 1 connected
Server: client 2 connected
Server: client 3 connected
Server: creating socket
Server: listening on port %d
Server: waiting for client 1
Server: waiting for client 2
Server: waiting for client 3
End