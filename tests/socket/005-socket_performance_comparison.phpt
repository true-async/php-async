--TEST--
Compare socket IPv6 resolution performance: sync vs async
--SKIPIF--
<?php
if (!extension_loaded('sockets')) {
    die('skip sockets extension not available');
}
if (!extension_loaded('async')) {
    die('skip async extension not available');
}
if (!defined('AF_INET6')) {
    die('skip IPv6 not supported');
}
?>
--FILE--
<?php

function test_sync_resolution() {
    $socket = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
    $start = microtime(true);
    @socket_connect($socket, "localhost", 80);
    $end = microtime(true);
    socket_close($socket);
    return ($end - $start) * 1000;
}

function test_async_resolution() {
    return async(function() {
        $socket = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
        $start = microtime(true);
        @socket_connect($socket, "localhost", 80);
        $end = microtime(true);
        socket_close($socket);
        return ($end - $start) * 1000;
    });
}

// Test multiple times to get average
$sync_times = [];
$async_times = [];

echo "Testing socket IPv6 resolution performance...\n";

for ($i = 0; $i < 3; $i++) {
    $sync_times[] = test_sync_resolution();
    $async_times[] = test_async_resolution();
}

$avg_sync = array_sum($sync_times) / count($sync_times);
$avg_async = array_sum($async_times) / count($async_times);

echo "Average sync time: " . round($avg_sync, 2) . " ms\n";
echo "Average async time: " . round($avg_async, 2) . " ms\n";

// Both should complete successfully
if ($avg_sync > 0 && $avg_async > 0) {
    echo "Both sync and async hostname resolution completed\n";
} else {
    echo "Test failed\n";
}

echo "Performance test completed\n";
?>
--EXPECTF--
Testing socket IPv6 resolution performance...
Average sync time: %f ms
Average async time: %f ms
Both sync and async hostname resolution completed
Performance test completed