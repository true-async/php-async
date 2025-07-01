--TEST--
All sleep functions async comparison: sleep(), usleep(), time_nanosleep(), time_sleep_until()
--SKIPIF--
<?php
if (!function_exists("sleep") || !function_exists("usleep") || 
    !function_exists("time_nanosleep") || !function_exists("time_sleep_until")) {
    echo "skip one or more sleep functions are not available";
}
?>
--FILE--
<?php

use function Async\spawn;

echo "Starting full sleep functions test\n";

spawn(function () {
    echo "Testing sleep() async\n";
    $start = microtime(true);
    $result = sleep(1);
    $elapsed = microtime(true) - $start;
    echo "sleep(1) result: $result, elapsed: " . round($elapsed, 1) . "s\n";
});

spawn(function () {
    echo "Testing usleep() async\n";
    $start = microtime(true);
    usleep(200000); // 0.2s
    $elapsed = microtime(true) - $start;
    echo "usleep(200000) elapsed: " . round($elapsed, 1) . "s\n";
});

spawn(function () {
    echo "Testing time_nanosleep() async\n";
    $start = microtime(true);
    $result = time_nanosleep(0, 100000000); // 0.1s
    $elapsed = microtime(true) - $start;
    echo "time_nanosleep(0, 100000000) result: " . ($result ? "true" : "false") . ", elapsed: " . round($elapsed, 1) . "s\n";
});

spawn(function () {
    echo "Testing time_sleep_until() async\n";
    $start = microtime(true);
    $target = microtime(true) + 0.15;
    $result = time_sleep_until($target);
    $elapsed = microtime(true) - $start;
    $elapsedRounded = ceil(($elapsed - 1e-9) * 10) / 10;
    echo "time_sleep_until() result: " . ($result ? "true" : "false") . ", elapsed: " . $elapsedRounded . "s\n";
});

spawn(function() {
    echo "Background task running\n";
});

echo "Full sleep functions test completed\n";
?>
--EXPECTF--
Starting full sleep functions test
Full sleep functions test completed
Testing sleep() async
Testing usleep() async
Testing time_nanosleep() async
Testing time_sleep_until() async
Background task running
time_nanosleep(0, 100000000) result: true, elapsed: 0.1s
time_sleep_until() result: true, elapsed: 0.2s
usleep(200000) elapsed: 0.2s
sleep(1) result: 0, elapsed: %ds