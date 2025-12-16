--TEST--
SSL Stream: stream_socket_accept() with SSL and timeout
--SKIPIF--
<?php if (!extension_loaded('openssl')) die('skip openssl extension not available'); ?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Start SSL accept timeout test\n";

// Get certificate files from the test directory
$cert_file = __DIR__ . '/ssl_test_cert.pem';
$key_file = __DIR__ . '/ssl_test_key.pem';

// Server coroutine that tests SSL accept timeout
$server = spawn(function() use ($cert_file, $key_file) {
    echo "SSL Server: creating SSL context\n";

    // Create SSL context with self-signed certificate files
    $context = stream_context_create([
        'ssl' => [
            'local_cert' => $cert_file,
            'local_pk' => $key_file,
            'verify_peer' => false,
            'allow_self_signed' => true,
        ]
    ]);

    echo "SSL Server: starting SSL server\n";
    $socket = stream_socket_server("ssl://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
    if (!$socket) {
        echo "SSL Server: failed to start - $errstr ($errno)\n";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    echo "SSL Server: listening on $address\n";

    echo "SSL Server: accepting with timeout\n";
    // This should use network_async_accept_incoming() in async mode
    // instead of the old inefficient php_poll2_async()
    $client = @stream_socket_accept($socket, 1); // 1 second timeout

    if ($client === false) {
        echo "SSL Server: timeout occurred as expected\n";
    } else {
        echo "SSL Server: unexpected client connection\n";
        fclose($client);
    }

    fclose($socket);
    echo "SSL Server: finished\n";
});

await_all([$server]);

echo "End SSL accept timeout test\n";

?>
--EXPECTF--
Start SSL accept timeout test
SSL Server: creating SSL context
SSL Server: starting SSL server
SSL Server: listening on %s:%d
SSL Server: accepting with timeout
SSL Server: timeout occurred as expected
SSL Server: finished
End SSL accept timeout test
