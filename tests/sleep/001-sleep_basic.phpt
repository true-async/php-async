--TEST--
sleep() async basic functionality
--SKIPIF--
<?php
if (!function_exists("sleep")) echo "skip sleep() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

$start_time = microtime(true);

spawn(function () use ($start_time) {
    echo "Starting async sleep test\n";
    
    $result = sleep(1);
    
    $elapsed = microtime(true) - $start_time;
    echo "Sleep returned: $result\n";
    echo "Elapsed time >= 1s: " . ($elapsed >= 1.0 ? "yes" : "no") . "\n";
    echo "Sleep test completed\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async sleep test
Other async task executing
Sleep returned: 0
Elapsed time >= 1s: yes
Sleep test completed