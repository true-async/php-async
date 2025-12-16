--TEST--
UDP basic operations with stream_socket_recvfrom/sendto in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\delay;

$output = [];

$output['1'] = "Start UDP basic operations test";

$address = null;

// Server coroutine
$server = spawn(function() use(&$address, &$output) {
    $output['2'] = "Server: creating UDP socket";
    $socket = stream_socket_server("udp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND);
    if (!$socket) {
        $output['2a'] = "Server: failed to create socket: $errstr";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    $address = "udp://$address";
    $output['3'] = "Server: listening on $address";

    // Wait for incoming data
    $output['4'] = "Server: waiting for UDP data";
    $data = stream_socket_recvfrom($socket, 1024, 0, $peer);
    $output['6'] = "Server: received '$data' from $peer";

    // Send response back
    $response = "Hello from UDP server";
    $bytes = stream_socket_sendto($socket, $response, 0, $peer);
    $output['7'] = "Server: sent $bytes bytes response";

    fclose($socket);
    return $address;
});

// Client coroutine
$client = spawn(function() use (&$address, &$output) {
    // Wait for server to start with retry logic
    $address = null;
    for ($attempts = 0; $attempts < 5; $attempts++) {
        delay(10);
        if ($address) {
            break;
        }
    }

    if (!$address) {
        throw new Exception("Client: failed to get server address after 5 attempts");
    }

    $output['4a'] = "Client: connecting to $address";
    $socket = stream_socket_client($address, $errno, $errstr);
    if (!$socket) {
        $output['4b'] = "Client: failed to connect: $errstr";
        return;
    }

    // Send data to server
    $message = "Hello from UDP client";
    $bytes = stream_socket_sendto($socket, $message);
    $output['5'] = "Client: sent $bytes bytes";

    // Receive response
    $response = stream_socket_recvfrom($socket, 1024);
    $output['8'] = "Client: received '$response'";

    fclose($socket);
});

await_all([$server, $client]);
$output['9'] = "End UDP basic operations test";

// Sort output by keys to ensure deterministic test results
ksort($output);

// Output sorted results
foreach ($output as $line) {
    echo $line . "\n";
}

?>
--EXPECTF--
Start UDP basic operations test
Server: creating UDP socket
Server: listening on udp://127.0.0.1:%d
Server: waiting for UDP data
Client: connecting to udp://127.0.0.1:%d
Client: sent 21 bytes
Server: received 'Hello from UDP client' from 127.0.0.1:%d
Server: sent 21 bytes response
Client: received 'Hello from UDP server'
End UDP basic operations test