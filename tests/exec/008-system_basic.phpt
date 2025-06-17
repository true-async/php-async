--TEST--
system() async basic functionality
--SKIPIF--
<?php
if (!function_exists("system")) echo "skip system() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

spawn(function () {
    echo "Starting async system test\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $return_var = null;
    
    $output = system($php . ' -r "echo \"Hello from async system\n\";"', $return_var);
    
    echo "Output: " . $output;
    echo "Return code: " . $return_var . "\n";
    echo "System test completed successfully\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async system test
Other async task executing
Hello from async system
Output: Hello from async system
Return code: 0
System test completed successfully