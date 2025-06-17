--TEST--
stream_socket_client and stream_socket_server with coroutine switching
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
use function Async\suspend;

echo "Start\n";

// Shared variable for server address
$address = null;

// Server coroutine
$server = spawn(function() use (&$address) {
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
    echo "Server: client connected\n";

    $data = fread($client, 1024);
    echo "Server: received '$data'\n";

    fwrite($client, "Hello from server");
    echo "Server: response sent\n";

    fclose($client);
    fclose($socket);
});

// Client coroutine
$client = spawn(function() use (&$address) {
    // Wait for the server to set the address
    while ($address === null) {
        // Yield control for other coroutines
        delay(10);
    }

    echo "Client: connecting\n";
    $sock = stream_socket_client($address, $errno, $errstr);
    if (!$sock) {
        echo "Client: failed to connect: $errstr\n";
        return;
    }

    fwrite($sock, "Hello from client");
    echo "Client: sent request\n";

    $response = fread($sock, 1024);
    echo "Client: received '$response'\n";

    fclose($sock);
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
Worker: doing work while server waits
Server: listening
Server: waiting for connection
Client: connecting
Server: client connected
Client: sent request
Server: received 'Hello from client'
Server: response sent
Client: received 'Hello from server'
End