--TEST--
Concurrent UDP operations with multiple servers and clients in async context
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

$output = [];

$output['1'] = "Start concurrent UDP operations test";

$server1_address = null;
$server2_address = null;

// Server1 coroutine
$server1 = spawn(function() use (&$server1_address, &$output) {
    $output['2'] = "Server1: creating UDP socket";
    $socket = stream_socket_server("udp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND);
    if (!$socket) {
        $output['2a'] = "Server1: failed to create socket: $errstr";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    $server1_address = "udp://$address";
    $output['3'] = "Server1: listening on $server1_address";

    // Wait for incoming data
    $output['5'] = "Server1: waiting for UDP data";
    $data = stream_socket_recvfrom($socket, 1024, 0, $peer);
    $output['7'] = "Server1: received '$data' from $peer";

    // Send response back
    $response = "Hello from UDP server1";
    $bytes = stream_socket_sendto($socket, $response, 0, $peer);
    $output['8'] = "Server1: sent $bytes bytes response";

    fclose($socket);
    return $server1_address;
});

// Server2 coroutine
$server2 = spawn(function() use (&$server2_address, &$output) {
    $output['2b'] = "Server2: creating UDP socket";
    $socket = stream_socket_server("udp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND);
    if (!$socket) {
        $output['2c'] = "Server2: failed to create socket: $errstr";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    $server2_address = "udp://$address";
    $output['4'] = "Server2: listening on $server2_address";

    // Wait for incoming data
    $output['5b'] = "Server2: waiting for UDP data";
    $data = stream_socket_recvfrom($socket, 1024, 0, $peer);
    $output['7b'] = "Server2: received '$data' from $peer";

    // Send response back
    $response = "Hello from UDP server2";
    $bytes = stream_socket_sendto($socket, $response, 0, $peer);
    $output['8b'] = "Server2: sent $bytes bytes response";

    fclose($socket);
    return $server2_address;
});

// Client1 coroutine
$client1 = spawn(function() use (&$server1_address, &$output) {
    // Wait for server1 to start
    for ($attempts = 0; $attempts < 10; $attempts++) {
        delay(10);
        if ($server1_address) {
            break;
        }
    }

    if (!$server1_address) {
        throw new Exception("Client1: failed to get server1 address after 10 attempts");
    }

    $output['6'] = "Client1: connecting to $server1_address";
    $socket = stream_socket_client($server1_address, $errno, $errstr);
    if (!$socket) {
        $output['6a'] = "Client1: failed to connect: $errstr";
        return;
    }

    // Send data to server1
    $message = "Hello from UDP client1";
    $bytes = stream_socket_sendto($socket, $message);
    $output['6b'] = "Client1: sent $bytes bytes";

    // Receive response
    $response = stream_socket_recvfrom($socket, 1024);
    $output['9'] = "Client1: received '$response'";

    fclose($socket);
});

// Client2 coroutine
$client2 = spawn(function() use (&$server2_address, &$output) {
    // Wait for server2 to start
    for ($attempts = 0; $attempts < 10; $attempts++) {
        delay(10);
        if ($server2_address) {
            break;
        }
    }

    if (!$server2_address) {
        throw new Exception("Client2: failed to get server2 address after 10 attempts");
    }

    $output['6c'] = "Client2: connecting to $server2_address";
    $socket = stream_socket_client($server2_address, $errno, $errstr);
    if (!$socket) {
        $output['6d'] = "Client2: failed to connect: $errstr";
        return;
    }

    // Send data to server2
    $message = "Hello from UDP client2";
    $bytes = stream_socket_sendto($socket, $message);
    $output['6e'] = "Client2: sent $bytes bytes";

    // Receive response
    $response = stream_socket_recvfrom($socket, 1024);
    $output['9b'] = "Client2: received '$response'";

    fclose($socket);
});

awaitAll([$server1, $server2, $client1, $client2]);
$output['z'] = "End concurrent UDP operations test";

// Sort output by keys to ensure deterministic test results
ksort($output);

// Output sorted results
foreach ($output as $line) {
    echo $line . "\n";
}

?>
--EXPECTF--
Start concurrent UDP operations test
Server1: creating UDP socket
Server2: creating UDP socket
Server1: listening on udp://127.0.0.1:%d
Server2: listening on udp://127.0.0.1:%d
Server1: waiting for UDP data
Server2: waiting for UDP data
Client1: connecting to udp://127.0.0.1:%d
Client1: sent 22 bytes
Client2: connecting to udp://127.0.0.1:%d
Client2: sent 22 bytes
Server1: received 'Hello from UDP client1' from 127.0.0.1:%d
Server2: received 'Hello from UDP client2' from 127.0.0.1:%d
Server1: sent 22 bytes response
Server2: sent 22 bytes response
Client1: received 'Hello from UDP server1'
Client2: received 'Hello from UDP server2'
End concurrent UDP operations test