--TEST--
time_sleep_until() async basic functionality
--SKIPIF--
<?php
if (!function_exists("time_sleep_until")) echo "skip time_sleep_until() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

$start_time = microtime(true);

spawn(function () use ($start_time) {
    echo "Starting async time_sleep_until test\n";
    
    $target_time = microtime(true) + 0.4; // Sleep for 0.4 seconds
    $result = time_sleep_until($target_time);
    
    $elapsed = microtime(true) - $start_time;
    echo "time_sleep_until returned: " . ($result === true ? "true" : "false") . "\n";
    echo "time_sleep_until test completed\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async time_sleep_until test
Other async task executing
time_sleep_until returned: true
time_sleep_until test completed