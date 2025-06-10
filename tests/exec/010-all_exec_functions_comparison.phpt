--TEST--
All exec functions async comparison: exec(), system(), passthru(), shell_exec()
--SKIPIF--
<?php
if (!function_exists("exec") || !function_exists("system") || 
    !function_exists("passthru") || !function_exists("shell_exec")) {
    echo "skip one or more exec functions are not available";
}
?>
--FILE--
<?php

use function Async\spawn;

echo "Starting full exec functions test\n";

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
    echo "Testing system() async\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $return_var = null;
    
    $output = system($php . ' -r "echo \'system result\';"', $return_var);
    
    echo "system() result: " . $output . " (code: $return_var)\n";
});

spawn(function () {
    echo "Testing passthru() async\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $return_var = null;
    
    ob_start();
    passthru($php . ' -r "echo \'passthru result\';"', $return_var);
    $output = ob_get_clean();
    
    echo "passthru() result: " . trim($output) . " (code: $return_var)\n";
});

spawn(function () {
    echo "Testing shell_exec() async\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $output = shell_exec($php . ' -r "echo \'shell_exec result\';"');
    
    echo "shell_exec() result: " . trim($output) . "\n";
});

spawn(function() {
    echo "Background task running\n";
});

echo "Full exec functions test completed\n";
?>
--EXPECT--
Starting full exec functions test
Full exec functions test completed
Testing exec() async
Testing system() async
Testing passthru() async
Testing shell_exec() async
Background task running
exec result
exec() result: exec result (code: 0)
system result
system() result: system result (code: 0)
passthru result
passthru() result: passthru result (code: 0)
shell_exec() result: shell_exec result