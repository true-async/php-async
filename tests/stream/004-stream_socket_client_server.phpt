--TEST--
stream_socket_client and stream_socket_server with coroutine switching
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\delay;
use function Async\suspend;

echo "Start\n";

// Shared variables for server address and output collection
$address = null;
$output = [];

// Server coroutine
$server = spawn(function() use (&$address, &$output) {
    echo "Server: creating socket\n";
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$socket) {
        echo "Server: failed to create socket\n";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    echo "Server: listening\n";

    echo "Server: waiting for connection\n";
    $client = stream_socket_accept($socket);
    $output[] = "Server: client connected";

    $data = fread($client, 1024);
    $output[] = "Server: received '$data'";

    fwrite($client, "Hello from server");
    $output[] = "Server: response sent";

    fclose($client);
    fclose($socket);
});

// Client coroutine
$client = spawn(function() use (&$address, &$output) {
    // Wait for the server to set the address
    while ($address === null) {
        // Yield control for other coroutines
        delay(10);
    }

    $output[] = "Client: connecting";
    $sock = stream_socket_client($address, $errno, $errstr);
    if (!$sock) {
        echo "Client: failed to connect: $errstr\n";
        return;
    }

    fwrite($sock, "Hello from client");
    $output[] = "Client: sent request";

    $response = fread($sock, 1024);
    $output[] = "Client: received '$response'";

    fclose($sock);
});

// Worker coroutine for parallel execution
$worker = spawn(function() {
    echo "Worker: doing work while server waits\n";
});

await_all([$server, $client, $worker]);

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
Worker: doing work while server waits
Server: listening
Server: waiting for connection
Client: connecting
Client: received 'Hello from server'
Client: sent request
Server: client connected
Server: received 'Hello from client'
Server: response sent
End