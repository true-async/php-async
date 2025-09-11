--TEST--
SSL Stream: concurrent SSL accept operations without EventLoop conflicts
--SKIPIF--
<?php if (!extension_loaded('openssl')) die('skip openssl extension not available'); ?>
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllOrFail;
use function Async\delay;

echo "Start SSL concurrent accept test\n";

// Get certificate files from the test directory
$cert_file = __DIR__ . '/ssl_test_cert.pem';
$key_file = __DIR__ . '/ssl_test_key.pem';

$servers_ready = 0;
$servers_completed = 0;
$output = [];
$monitor = null;

// Helper function to create SSL server
function create_ssl_server($id, $cert_file, $key_file, &$monitor, &$servers_ready, &$servers_completed, &$output) {
    return spawn(function() use ($id, $cert_file, $key_file, &$servers_ready, &$servers_completed, &$output) {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $cert_file,
                'local_pk' => $key_file,
                'verify_peer' => false,
                'allow_self_signed' => true,
            ]
        ]);

        $socket = stream_socket_server("ssl://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
        if (!$socket) {
            $monitor->cancel();
            $output[] = "SSL Server $id: failed to start - $errstr";
            return;
        }

        $address = stream_socket_get_name($socket, false);
        $output[] = "SSL Server $id: listening on $address";

        $servers_ready++;

        // All servers try to accept concurrently
        // This tests that network_async_accept_incoming() doesn't cause EventLoop conflicts
        // which was the main issue with php_poll2_async()
        $client = @stream_socket_accept($socket, 2); // 2 second timeout

        if ($client === false) {
            $output[] = "SSL Server $id: timeout occurred";
        } else {
            $output[] = "SSL Server $id: client connected";
            fclose($client);
        }

        fclose($socket);
        $servers_completed++;
    });
}

echo "Creating multiple concurrent SSL servers\n";

// Create 3 concurrent SSL servers
// This is the key test - multiple SSL accepts should work without EventLoop conflicts
$server1 = create_ssl_server(1, $cert_file, $key_file, $monitor, $servers_ready, $servers_completed, $output);
$server2 = create_ssl_server(2, $cert_file, $key_file, $monitor, $servers_ready, $servers_completed, $output);
$server3 = create_ssl_server(3, $cert_file, $key_file, $monitor, $servers_ready, $servers_completed, $output);

// Monitor coroutine
$monitor = spawn(function() use (&$servers_ready, &$servers_completed) {
    echo "Monitor: waiting for servers to be ready\n";

    while ($servers_ready < 3) {
        delay(50);
    }

    echo "Monitor: all servers ready, waiting for completion\n";

    while ($servers_completed < 3) {
        delay(50);
    }

    echo "Monitor: all servers completed\n";
});

awaitAllOrFail([$server1, $server2, $server3, $monitor]);

// Sort output for deterministic results
sort($output);
foreach ($output as $message) {
    echo $message . "\n";
}

echo "End SSL concurrent accept test\n";

?>
--EXPECTF--
Start SSL concurrent accept test
Creating multiple concurrent SSL servers
Monitor: waiting for servers to be ready
Monitor: all servers ready, waiting for completion
Monitor: all servers completed
SSL Server 1: listening on %s:%d
SSL Server 1: timeout occurred
SSL Server 2: listening on %s:%d
SSL Server 2: timeout occurred
SSL Server 3: listening on %s:%d
SSL Server 3: timeout occurred
End SSL concurrent accept test
