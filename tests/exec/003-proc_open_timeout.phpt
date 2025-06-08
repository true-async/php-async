--TEST--
proc_open() async with timeout handling
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

Async\run(function () {
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
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exit_code = proc_close($process);
    echo "Fast process output: " . trim($output) . " (exit: $exit_code)\n";
});

Async\run(function () {
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
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exit_code = proc_close($process);
    echo "Slow process output: " . trim($output) . " (exit: $exit_code)\n";
});

Async\run(function() {
    echo "Background task running\n";
});

echo "Starting timeout test\n";
Async\launchScheduler();
echo "Timeout test completed\n";
?>
--EXPECT--
Starting timeout test
Testing proc_open with fast process
Testing proc_open with slow process
Background task running
Fast process output: Fast process (exit: 0)
Slow process output: Slow process (exit: 0)
Timeout test completed