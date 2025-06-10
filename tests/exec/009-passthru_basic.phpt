--TEST--
passthru() async basic functionality
--SKIPIF--
<?php
if (!function_exists("passthru")) echo "skip passthru() is not available";
?>
--FILE--
<?php

use function Async\spawn;

echo "Main thread start\n";

spawn(function () {
    echo "Starting async passthru test\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $return_var = null;
    
    passthru($php . ' -r "echo \'Hello from async passthru\';"', $return_var);
    
    echo "Return code: " . $return_var . "\n";
    echo "Passthru test completed successfully\n";
});

spawn(function() {
    echo "Other async task executing\n";
});

echo "Main thread end\n";
?>
--EXPECT--
Main thread start
Main thread end
Starting async passthru test
Other async task executing
Hello from async passthruReturn code: 0
Passthru test completed successfully