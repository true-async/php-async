--TEST--
exec() multiple concurrent async executions
--SKIPIF--
<?php
if (!function_exists("exec")) echo "skip exec() is not available";
?>
--FILE--
<?php

use function Async\spawn;

$results = [];

echo "Main start\n";

spawn(function () use (&$results) {
    echo "Exec 1 starting\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $output = [];
    $return_var = null;
    
    exec($php . ' -r "usleep(30000); echo \'Exec 1 done\';"', $output, $return_var);
    
    $results[] = "Exec 1: " . implode("", $output) . " (exit: $return_var)";
    echo "Exec 1 completed\n";
});

spawn(function () use (&$results) {
    echo "Exec 2 starting\n";
    
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }
    
    $output = [];
    $return_var = null;
    
    exec($php . ' -r "usleep(20000); echo \'Exec 2 done\';"', $output, $return_var);
    
    $results[] = "Exec 2: " . implode("", $output) . " (exit: $return_var)";
    echo "Exec 2 completed\n";
});

spawn(function() {
    echo "Other task executing\n";
});

echo "Main end\n";
?>
--EXPECTF--
Main start
Main end
%a