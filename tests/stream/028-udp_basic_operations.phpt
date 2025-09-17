--TEST--
UDP basic operations with stream_socket_recvfrom/sendto in async context
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

echo "Start UDP basic operations test\n";

// Server coroutine
$server = spawn(function() {
    echo "Server: creating UDP socket\n";
    $socket = stream_socket_server("udp://127.0.0.1:0", $errno, $errstr);
    if (!$socket) {
        echo "Server: failed to create socket: $errstr\n";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    echo "Server: listening on $address\n";

    // Wait for incoming data
    echo "Server: waiting for UDP data\n";
    $data = stream_socket_recvfrom($socket, 1024, 0, $peer);
    echo "Server: received '$data' from $peer\n";

    // Send response back
    $response = "Hello from UDP server";
    $bytes = stream_socket_sendto($socket, $response, 0, $peer);
    echo "Server: sent $bytes bytes response\n";

    fclose($socket);
    return $address;
});

// Client coroutine
$client = spawn(function() use ($server) {
    // Wait for server to start with retry logic
    $address = null;
    for ($attempts = 0; $attempts < 5; $attempts++) {
        delay(10);
        $address = $server->getResult();
        if ($address) {
            break;
        }
    }

    if (!$address) {
        throw new Exception("Client: failed to get server address after 5 attempts");
    }

    echo "Client: connecting to $address\n";
    $socket = stream_socket_client($address, $errno, $errstr);
    if (!$socket) {
        echo "Client: failed to connect: $errstr\n";
        return;
    }

    // Send data to server
    $message = "Hello from UDP client";
    $bytes = stream_socket_sendto($socket, $message);
    echo "Client: sent $bytes bytes\n";

    // Receive response
    $response = stream_socket_recvfrom($socket, 1024);
    echo "Client: received '$response'\n";

    fclose($socket);
});

awaitAll([$server, $client]);
echo "End UDP basic operations test\n";

?>
--EXPECT--
Start UDP basic operations test
Server: creating UDP socket
Server: listening on udp://127.0.0.1:0
Server: waiting for UDP data
Client: connecting to udp://127.0.0.1:0
Client: sent 21 bytes
Server: received 'Hello from UDP client' from 127.0.0.1:0
Server: sent 21 bytes response
Client: received 'Hello from UDP server'
End UDP basic operations test