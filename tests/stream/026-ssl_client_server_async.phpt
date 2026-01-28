--TEST--
SSL Stream: full SSL client-server async communication
--SKIPIF--
<?php if (!extension_loaded('openssl')) die('skip openssl extension not available'); ?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all_or_fail;
use function Async\delay;

echo "Start SSL client-server test\n";

// Get certificate files from the test directory
$cert_file = __DIR__ . '/ssl_test_cert.pem';
$key_file = __DIR__ . '/ssl_test_key.pem';

// Shared variables for communication
$address = null;
$output = [];
$client = null;

// SSL Server coroutine
$server = spawn(function() use (&$address, &$output, &$client, $cert_file, $key_file) {
    echo "SSL Server: creating SSL context\n";

    $context = stream_context_create([
        'ssl' => [
            'local_cert' => $cert_file,
            'local_pk' => $key_file,
            'verify_peer' => false,
            'allow_self_signed' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
        ]
    ]);

    $socket = stream_socket_server("ssl://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
    if (!$socket) {
        echo "SSL Server: failed to create socket - $errstr\n";
        return;
    }

    $server_address = stream_socket_get_name($socket, false);
    // Client needs ssl:// prefix to connect via SSL
    $address = 'ssl://' . $server_address;
    echo "SSL Server: listening on $server_address\n";

    echo "SSL Server: waiting for SSL connection\n";
    // This should use network_async_accept_incoming() instead of php_poll2_async()
    $client = stream_socket_accept($socket, 10); // 10 second timeout

    if (!$client) {
        echo "SSL Server: failed to accept client\n";
        return;
    }

    $output[] = "SSL Server: client connected";

    $data = fread($client, 1024);
    $output[] = "SSL Server: received '$data'";

    fwrite($client, "Hello from SSL server");
    $output[] = "SSL Server: response sent";

    fclose($client);
    fclose($socket);
});

// SSL Client coroutine
$client = spawn(function() use (&$address, &$output) {
    // Wait for server to set address
    while ($address === null) {
        delay(10);
    }

    echo "SSL Client: connecting to $address\n";

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ]
    ]);

    $sock = stream_socket_client($address, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) {
        echo "SSL Client: failed to connect - $errstr ($errno)\n";
        return;
    }

    $output[] = "SSL Client: connected successfully";

    fwrite($sock, "Hello from SSL client");
    $output[] = "SSL Client: sent request";

    $response = fread($sock, 1024);
    $output[] = "SSL Client: received '$response'";

    fclose($sock);
});

await_all_or_fail([$server, $client]);

// Sort output for deterministic results
sort($output);
foreach ($output as $message) {
    echo $message . "\n";
}

echo "End SSL client-server test\n";

?>
--EXPECTF--
Start SSL client-server test
SSL Server: creating SSL context
SSL Server: listening on %s:%d
SSL Server: waiting for SSL connection
SSL Client: connecting to %s:%d
SSL Client: connected successfully
SSL Client: received 'Hello from SSL server'
SSL Client: sent request
SSL Server: client connected
SSL Server: received 'Hello from SSL client'
SSL Server: response sent
End SSL client-server test
