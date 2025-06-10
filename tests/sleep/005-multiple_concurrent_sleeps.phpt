--TEST--
Multiple concurrent async sleep operations
--SKIPIF--
<?php
if (!function_exists("sleep") || !function_exists("usleep")) {
    echo "skip sleep functions are not available";
}
?>
--FILE--
<?php

use function Async\spawn;

$results = [];
$start_time = microtime(true);

echo "Main start\n";

spawn(function () use (&$results, $start_time) {
    echo "Sleep 1 starting (1s)\n";
    sleep(1);
    $elapsed = microtime(true) - $start_time;
    $results[] = "Sleep 1: " . round($elapsed, 1) . "s";
    echo "Sleep 1 completed\n";
});

spawn(function () use (&$results, $start_time) {
    echo "Sleep 2 starting (0.5s)\n";
    usleep(500000);
    $elapsed = microtime(true) - $start_time;
    $results[] = "Sleep 2: " . round($elapsed, 1) . "s";
    echo "Sleep 2 completed\n";
});

spawn(function () use (&$results, $start_time) {
    echo "Sleep 3 starting (0.3s)\n";
    time_nanosleep(0, 300000000);
    $elapsed = microtime(true) - $start_time;
    $results[] = "Sleep 3: " . round($elapsed, 1) . "s";
    echo "Sleep 3 completed\n";
});

spawn(function() {
    echo "Background task running\n";
});

echo "Main end\n";
?>
--EXPECT--
Main start
Main end
Sleep 1 starting (1s)
Sleep 2 starting (0.5s)
Sleep 3 starting (0.3s)
Background task running
Sleep 3 completed
Sleep 2 completed
Sleep 1 completed