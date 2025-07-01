--TEST--
time_nanosleep() async basic functionality
--SKIPIF--
<?php
if (!function_exists("time_nanosleep")) echo "skip time_nanosleep() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

$start_time = microtime(true);

spawn(function () use ($start_time) {
    echo "Starting async time_nanosleep test\n";
    
    $result = time_nanosleep(0, 300000000); // 0.3 seconds
    
    $elapsed = microtime(true) - $start_time;
    echo "time_nanosleep returned: " . ($result === true ? "true" : "false") . "\n";
    echo "Elapsed time >= 0.2s: " . ($elapsed >= 0.2 ? "yes" : "no") . "\n";
    echo "time_nanosleep test completed\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async time_nanosleep test
Other async task executing
time_nanosleep returned: true
Elapsed time >= 0.2s: yes
time_nanosleep test completed