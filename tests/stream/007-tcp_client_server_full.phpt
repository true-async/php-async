--TEST--
Full TCP client-server test with coroutine switching
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
use function Async\suspend;

echo "Start\n";

$server_port = null;

// Server coroutine
$server = spawn(function() use (&$server_port) {
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
    $client = stream_socket_accept($socket);
    echo "Server: client connected\n";
    
    $data = fread($client, 1024);
    echo "Server: received '$data' and sending response...\n";
    
    fwrite($client, "Server response: $data");
    
    fclose($client);
    fclose($socket);
    return "server_done";
});

// Client coroutine
$client = spawn(function() use (&$server_port) {
    // Wait for server to start
    while ($server_port === null) {
        delay(1);
    }
    
    echo "Client: connecting to port $server_port...\n";

    $socket = stream_socket_client("tcp://127.0.0.1:$server_port", $errno, $errstr, 1);

    if (!$socket) {
        echo "Client: failed to connect - $errstr\n";
        return;
    }
    
    echo "Client: connected and send message...\n";
    fwrite($socket, "Hello from client");
    
    $response = fread($socket, 1024);
    echo "Client: received '$response'\n";
    
    fclose($socket);
    return "client_done";
});

// Worker to show parallel execution
$worker = spawn(function() {
    echo "Worker: finished\n";
    return "worker_done";
});

$results = awaitAll([$server, $client, $worker]);
echo "Results: " . implode(", ", $results) . "\n";
echo "End\n";

?>
--EXPECTF--
Start
Server: starting
Worker: finished
Server: listening on port %d
Server: accepting connections
Client: connecting to port %d...
Server: client connected
Client: connected and send message...
Server: received 'Hello from client' and sending response...
Client: received 'Server response: Hello from client'
Results: server_done, client_done, worker_done
End