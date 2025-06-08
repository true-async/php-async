--TEST--
proc_open() multiple async processes
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

$results = [];

Async\run(function () use (&$results) {
    echo "Process 1 starting\n";
    
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
        [$php, "-r", "usleep(50000); echo 'Process 1 done';"],
        $descriptorspec,
        $pipes
    );
    
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exit_code = proc_close($process);
    $results[] = "Process 1: " . trim($output) . " (exit: $exit_code)";
    echo "Process 1 completed\n";
});

Async\run(function () use (&$results) {
    echo "Process 2 starting\n";
    
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
        [$php, "-r", "usleep(30000); echo 'Process 2 done';"],
        $descriptorspec,
        $pipes
    );
    
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exit_code = proc_close($process);
    $results[] = "Process 2: " . trim($output) . " (exit: $exit_code)";
    echo "Process 2 completed\n";
});

Async\run(function() {
    echo "Other task executing\n";
});

echo "Main start\n";
Async\launchScheduler();

sort($results);
foreach ($results as $result) {
    echo $result . "\n";
}
echo "Main end\n";
?>
--EXPECT--
Main start
Process 1 starting
Process 2 starting
Other task executing
Process 2 completed
Process 1 completed
Process 1: Process 1 done (exit: 0)
Process 2: Process 2 done (exit: 0)
Main end