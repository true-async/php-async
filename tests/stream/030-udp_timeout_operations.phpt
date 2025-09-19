--TEST--
UDP timeout operations with stream_socket_recvfrom in async context
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

$output = [];

$output['1'] = "Start UDP timeout operations test";

$server_address = null;

// Server coroutine that tests timeout
$server = spawn(function() use (&$server_address, &$output) {
    $output['2'] = "Server: creating UDP socket";
    $socket = stream_socket_server("udp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND);
    if (!$socket) {
        $output['2a'] = "Server: failed to create socket: $errstr";
        return;
    }

    $address = stream_socket_get_name($socket, false);
    $server_address = "udp://$address";
    $output['3'] = "Server: listening on $server_address";

    // Set timeout to 2 seconds
    stream_set_timeout($socket, 2, 0);
    $output['4'] = "Server: set timeout to 2 seconds";

    // Try to receive data (should timeout)
    $output['5'] = "Server: waiting for UDP data (should timeout)";
    $start_time = microtime(true);
    $data = stream_socket_recvfrom($socket, 1024, 0, $peer);
    $end_time = microtime(true);

    $meta = stream_get_meta_data($socket);
    $elapsed = round($end_time - $start_time, 1);

    if ($meta['timed_out']) {
        $output['6'] = "Server: operation timed out after $elapsed seconds";
    } else {
        $output['6'] = "Server: received data (unexpected): '$data'";
    }

    fclose($socket);
    return $server_address;
});

awaitAll([$server]);
$output['z'] = "End UDP timeout operations test";

// Sort output by keys to ensure deterministic test results
ksort($output);

// Output sorted results
foreach ($output as $line) {
    echo $line . "\n";
}

?>
--EXPECTF--
Warning: stream_socket_recvfrom(): Socket operation timed out after 2.000000 seconds in %s on line %d
Start UDP timeout operations test
Server: creating UDP socket
Server: listening on udp://127.0.0.1:%d
Server: set timeout to 2 seconds
Server: waiting for UDP data (should timeout)
Server: operation timed out after %s seconds
End UDP timeout operations test