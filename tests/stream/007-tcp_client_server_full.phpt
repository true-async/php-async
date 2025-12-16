--TEST--
Full TCP client-server test with coroutine switching
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\delay;
use function Async\suspend;

echo "Start\n";

$server_port = null;
$output = [];

// Server coroutine
$server = spawn(function() use (&$server_port, &$output) {
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
    $output[] = "Server: client connected";
    
    $data = fread($client, 1024);
    $output[] = "Server: received '$data' and sending response...";
    
    fwrite($client, "Server response: $data");
    
    fclose($client);
    fclose($socket);
    return "server_done";
});

// Client coroutine
$client = spawn(function() use (&$server_port, &$output) {
    // Wait for server to start
    while ($server_port === null) {
        delay(1);
    }
    
    $output[] = "Client: connecting to port $server_port...";

    $socket = stream_socket_client("tcp://127.0.0.1:$server_port", $errno, $errstr, 1);

    if (!$socket) {
        echo "Client: failed to connect - $errstr\n";
        return;
    }
    
    $output[] = "Client: connected and send message...";
    fwrite($socket, "Hello from client");
    
    $response = fread($socket, 1024);
    $output[] = "Client: received '$response'";
    
    fclose($socket);
    return "client_done";
});

// Worker to show parallel execution
$worker = spawn(function() {
    echo "Worker: finished\n";
    return "worker_done";
});

[$results, $exceptions] = await_all([$server, $client, $worker]);

// Sort output for deterministic results
sort($output);
foreach ($output as $message) {
    echo $message . "\n";
}

echo "Results: " . implode(", ", $results) . "\n";
echo "End\n";

?>
--EXPECTF--
Start
Server: starting
Worker: finished
Server: listening on port %d
Server: accepting connections
Client: connected and send message...
Client: connecting to port %d...
Client: received 'Server response: Hello from client'
Server: client connected
Server: received 'Hello from client' and sending response...
Results: server_done, client_done, worker_done
End