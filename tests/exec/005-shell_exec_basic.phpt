--TEST--
shell_exec() async basic functionality  
--SKIPIF--
<?php
if (!function_exists("shell_exec")) echo "skip shell_exec() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

spawn(function () {
    echo "Starting async shell_exec test\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $output = shell_exec($php . ' -r "echo \'Hello from async shell_exec\';"');
    
    echo "Output: " . trim($output) . "\n";
    echo "Shell_exec test completed successfully\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async shell_exec test
Other async task executing
Output: Hello from async shell_exec
Shell_exec test completed successfully