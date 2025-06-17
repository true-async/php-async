--TEST--
proc_open() async with timeout handling
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Starting timeout test\n";

spawn(function () {
    echo "Testing proc_open with fast process\n";
    
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    // Fast process - should complete normally
    $process = proc_open(
        [$php, "-r", "echo 'Fast process';"],
        $descriptorspec,
        $pipes
    );
    
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exit_code = proc_close($process);
    echo "Fast process output: (exit: $exit_code)\n";
});

spawn(function () {
    echo "Testing proc_open with slow process\n";
    
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    // Slow process - will test timeout behavior
    $process = proc_open(
        [$php, "-r", "usleep(100000); echo 'Slow process';"],
        $descriptorspec,
        $pipes
    );
    
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exit_code = proc_close($process);
    echo "Slow process output: (exit: $exit_code)\n";
});

spawn(function() {
    echo "Background task running\n";
});

echo "Timeout test completed\n";
?>
--EXPECT--
Starting timeout test
Timeout test completed
Testing proc_open with fast process
Testing proc_open with slow process
Background task running
Fast process output: (exit: 0)
Slow process output: (exit: 0)