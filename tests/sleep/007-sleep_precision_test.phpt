--TEST--
Sleep functions precision and timing accuracy
--SKIPIF--
<?php
if (!function_exists("sleep") || !function_exists("usleep") || !function_exists("time_nanosleep")) {
    echo "skip sleep functions are not available";
}
?>
--FILE--
<?php

use function Async\spawn;

echo "Starting precision test\n";

spawn(function () {
    echo "Testing short sleep precision\n";
    
    $start = microtime(true);
    usleep(50000); // 50ms
    $elapsed = microtime(true) - $start;
    
    echo "usleep(50000) - Expected: ~0.05s, Actual: " . round($elapsed, 3) . "s\n";
    echo "Precision acceptable: " . (abs($elapsed - 0.05) < 0.01 ? "yes" : "no") . "\n";
});

spawn(function () {
    echo "Testing nanosleep precision\n";
    
    $start = microtime(true);
    time_nanosleep(0, 75000000); // 75ms
    $elapsed = microtime(true) - $start;
    
    echo "time_nanosleep(0, 75000000) - Expected: ~0.075s, Actual: " . round($elapsed, 3) . "s\n";
    echo "Precision acceptable: " . (abs($elapsed - 0.075) < 0.01 ? "yes" : "no") . "\n";
});

spawn(function() {
    echo "Background task running\n";
});

echo "Precision test completed\n";
?>
--EXPECT--
Starting precision test
Precision test completed
Testing short sleep precision
Testing nanosleep precision
Background task running
usleep(50000) - Expected: ~0.05s, Actual: 0.050s
Precision acceptable: yes
time_nanosleep(0, 75000000) - Expected: ~0.075s, Actual: 0.075s
Precision acceptable: yes