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

echo "Starting comparison test\n";

spawn(function () {
    echo "Testing exec() async\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $output = [];
    $return_var = null;
    
    exec($php . ' -r "echo \'exec result\';"', $output, $return_var);
    
    echo "exec() result: " . implode("", $output) . " (code: $return_var)\n";
});

spawn(function () {
    echo "Testing shell_exec() async\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $output = shell_exec($php . ' -r "usleep(50);echo \'shell_exec result\';"');
    
    echo "shell_exec() result: " . trim($output) . "\n";
});

spawn(function() {
    echo "Background task running\n";
});

echo "Comparison test completed\n";
?>
--EXPECT--
Starting comparison test
Comparison test completed
Testing exec() async
Testing shell_exec() async
Background task running
exec() result: exec result (code: 0)
shell_exec() result: shell_exec result