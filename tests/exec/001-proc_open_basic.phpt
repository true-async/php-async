--TEST--
proc_open() async basic functionality
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
if (DIRECTORY_SEPARATOR !== '\\') { die('skip Windows-only test'); }
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

spawn(function () {
    echo "Starting async proc_open test\n";
    
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $process = proc_open(
        [$php, "-r", "usleep(1000); echo 'Hello from async process';"],
        $descriptorspec,
        $pipes
    );
    
    if (!is_resource($process)) {
        echo "Failed to create process\n";
        return;
    }
    
    // Close stdin
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exit_code = proc_close($process);
    
    echo "Exit code: " . $exit_code . "\n";
    echo "Test completed successfully\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async proc_open test
Other async task executing
Exit code: 0
Test completed successfully