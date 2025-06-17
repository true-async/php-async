--TEST--
usleep() async basic functionality
--SKIPIF--
<?php
if (!function_exists("usleep")) echo "skip usleep() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

$start_time = microtime(true);

spawn(function () use ($start_time) {
    echo "Starting async usleep test\n";
    
    usleep(500000); // 0.5 seconds
    
    $elapsed = microtime(true) - $start_time;
    echo "Elapsed time >= 0.5s: " . ($elapsed >= 0.5 ? "yes" : "no") . "\n";
    echo "Usleep test completed\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async usleep test
Other async task executing
Elapsed time >= 0.5s: yes
Usleep test completed