--TEST--
exec() vs shell_exec() async comparison
--SKIPIF--
<?php
if (!function_exists("exec") || !function_exists("shell_exec")) {
    echo "skip exec() or shell_exec() is not available";
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Starting comparison test\n";

$results = [];

$tasks = [
    spawn(function () use (&$results) {
        echo "Testing exec() async\n";
        
        $php = getenv('TEST_PHP_EXECUTABLE');
        if ($php === false) {
            die("skip no php executable defined");
        }
        
        $output = [];
        $return_var = null;
        
        exec($php . ' -r "echo \'exec result\';"', $output, $return_var);
        
        $results[] = "exec() result: " . implode("", $output) . " (code: $return_var)";
    }),
    
    spawn(function () use (&$results) {
        echo "Testing shell_exec() async\n";
        
        $php = getenv('TEST_PHP_EXECUTABLE');
        if ($php === false) {
            die("skip no php executable defined");
        }
        
        $output = shell_exec($php . ' -r "usleep(50);echo \'shell_exec result\';"');
        
        $results[] = "shell_exec() result: " . trim($output);
    }),
    
    spawn(function() {
        echo "Background task running\n";
    })
];

echo "Comparison test completed\n";

// Wait for all tasks to complete
await_all($tasks);

// Sort and output results
sort($results);
foreach ($results as $result) {
    echo $result . "\n";
}
?>
--EXPECT--
Starting comparison test
Comparison test completed
Testing exec() async
Testing shell_exec() async
Background task running
exec() result: exec result (code: 0)
shell_exec() result: shell_exec result