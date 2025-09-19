--TEST--
TCP timeout operations with fread/fwrite in async context
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

$output = [];

$output['1'] = "Start TCP timeout operations test";

$server_address = null;

// Server coroutine that tests timeout
$server = spawn(function() use (&$server_address, &$output) {
    $output['2'] = "Server: creating TCP socket";
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$socket) {
        $output['2a'] = "Server: failed to create socket: $errstr";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    $server_address = "tcp://$address";
    $output['3'] = "Server: listening on $server_address";

    // Accept client connection
    $output['4'] = "Server: waiting for client connection";
    $client = stream_socket_accept($socket);
    if (!$client) {
        $output['4a'] = "Server: failed to accept client";
        fclose($socket);
        return;
    }

    $output['5'] = "Server: client connected";

    // Set timeout to 0.2 seconds on client socket
    stream_set_timeout($client, 0, 200000);
    $output['6'] = "Server: set read timeout to 0.2 seconds";

    // Try to read data (should timeout)
    $output['7'] = "Server: reading from client (should timeout)";
    $data = fread($client, 1024);

    $meta = stream_get_meta_data($client);

    if ($meta['timed_out']) {
        $output['8'] = "Server: read operation timed out";
    } else {
        $output['8'] = "Server: received data (unexpected): '$data'";
    }

    fclose($client);
    fclose($socket);
    return $server_address;
});

// Client coroutine that connects but doesn't send data immediately
$client = spawn(function() use (&$server_address, &$output) {
    // Wait for server to start
    for ($attempts = 0; $attempts < 3; $attempts++) {
        delay(10);
        if ($server_address) {
            break;
        }
    }

    if (!$server_address) {
        throw new Exception("Client: failed to get server address after 10 attempts");
    }

    $output['4b'] = "Client: connecting to $server_address";
    $socket = stream_socket_client($server_address, $errno, $errstr);
    if (!$socket) {
        $output['4c'] = "Client: failed to connect: $errstr";
        return;
    }

    $output['5b'] = "Client: connected to server";

    // Wait for server to timeout (don't send data)
    delay(500);

    fclose($socket);
});

awaitAll([$server, $client]);
$output['z'] = "End TCP timeout operations test";

// Sort output by keys to ensure deterministic test results
ksort($output);

// Output sorted results
foreach ($output as $line) {
    echo $line . "\n";
}

?>
--EXPECTF--
Start TCP timeout operations test
Server: creating TCP socket
Server: listening on tcp://127.0.0.1:%d
Server: waiting for client connection
Client: connecting to tcp://127.0.0.1:%d
Server: client connected
Client: connected to server
Server: set read timeout to 0.2 seconds
Server: reading from client (should timeout)
Server: read operation timed out
End TCP timeout operations test