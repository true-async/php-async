--TEST--
Full TCP client-server test with coroutine switching
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

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
    echo "Server: received '$data'\n";
    
    fwrite($client, "Server response: $data");
    echo "Server: sent response\n";
    
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
    
    echo "Client: connecting to port $server_port\n";
    $socket = stream_socket_client("tcp://127.0.0.1:$server_port", $errno, $errstr, 1);
    if (!$socket) {
        echo "Client: failed to connect - $errstr\n";
        return;
    }
    
    echo "Client: connected\n";
    fwrite($socket, "Hello from client");
    echo "Client: sent message\n";
    
    $response = fread($socket, 1024);
    echo "Client: received '$response'\n";
    
    fclose($socket);
    return "client_done";
});

// Worker to show parallel execution
$worker = spawn(function() {
    echo "Worker: working while TCP operations happen\n";
    for ($i = 1; $i <= 3; $i++) {
        echo "Worker: step $i\n";
        delay(1);
    }
    echo "Worker: finished\n";
    return "worker_done";
});

$results = awaitAll([$server, $client, $worker]);
echo "Results: " . implode(", ", $results) . "\n";
echo "End\n";

?>
--EXPECT--
Start
Server: starting
Server: listening on port %d
Server: accepting connections
Worker: working while TCP operations happen
Worker: step 1
Client: connecting to port %d
Client: connected
Client: sent message
Server: client connected
Server: received 'Hello from client'
Server: sent response
Worker: step 2
Client: received 'Server response: Hello from client'
Worker: step 3
Worker: finished
Results: server_done, client_done, worker_done
End