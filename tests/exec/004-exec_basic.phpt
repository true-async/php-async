--TEST--
exec() async basic functionality
--SKIPIF--
<?php
if (!function_exists("exec")) echo "skip exec() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

spawn(function () {
    echo "Starting async exec test\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $output = [];
    $return_var = null;
    
    exec($php . ' -r "echo \'Hello from async exec\';"', $output, $return_var);
    
    echo "Output: " . implode("\n", $output) . "\n";
    echo "Return code: " . $return_var . "\n";
    echo "Exec test completed successfully\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async exec test
Other async task executing
Output: Hello from async exec
Return code: 0
Exec test completed successfully