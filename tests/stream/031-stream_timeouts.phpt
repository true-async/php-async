--TEST--
Stream operation timeouts in async context
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

echo "Start stream timeout test\n";

// Slow server that delays responses
$server = spawn(function() {
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$socket) {
        echo "Server: failed to create socket\n";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    echo "Server: listening on $address\n";

    $client = stream_socket_accept($socket);
    if ($client) {
        echo "Server: client connected\n";

        // Simulate slow processing
        echo "Server: processing slowly...\n";
        delay(200); // 200ms delay

        $data = fread($client, 1024);
        echo "Server: received: '$data'\n";

        fwrite($client, "Delayed response");
        fclose($client);
    }

    fclose($socket);
    return $address;
});

// Fast client that expects quick response
$fast_client = spawn(function() use ($server) {
    $address = null;
    for ($attempts = 0; $attempts < 5; $attempts++) {
        delay(10);
        $address = $server->getResult();
        if ($address) {
            break;
        }
    }

    if (!$address) {
        throw new Exception("Fast client: failed to get server address");
    }

    echo "Fast client: connecting to $address\n";

    // Set short timeout
    $context = stream_context_create([
        'socket' => ['timeout' => 0.1] // 100ms timeout
    ]);

    $socket = stream_socket_client($address, $errno, $errstr, 0.1);
    if (!$socket) {
        echo "Fast client: connection timeout as expected\n";
        return;
    }

    echo "Fast client: connected\n";
    fwrite($socket, "Fast request");

    // This should timeout
    $response = fread($socket, 1024);
    if ($response) {
        echo "Fast client: received: '$response'\n";
    } else {
        echo "Fast client: read timeout as expected\n";
    }

    fclose($socket);
});

// Patient client that waits longer
$patient_client = spawn(function() use ($server) {
    delay(50); // Let fast client go first

    $address = $server->getResult();
    if (!$address) {
        echo "Patient client: no server address\n";
        return;
    }

    echo "Patient client: connecting to $address\n";
    $socket = stream_socket_client($address, $errno, $errstr, 1.0); // 1 second timeout

    if ($socket) {
        echo "Patient client: connected\n";
        fwrite($socket, "Patient request");

        $response = fread($socket, 1024);
        echo "Patient client: received: '$response'\n";
        fclose($socket);
    } else {
        echo "Patient client: connection failed: $errstr\n";
    }
});

awaitAll([$server, $fast_client, $patient_client]);
echo "End stream timeout test\n";

?>
--EXPECTF--
Start stream timeout test
Server: listening on tcp://127.0.0.1:%d
Fast client: connecting to tcp://127.0.0.1:%d
%a
Patient client: connecting to tcp://127.0.0.1:%d
Server: client connected
Server: processing slowly...
%a
Patient client: connected
Server: received: '%s'
Patient client: received: 'Delayed response'
End stream timeout test