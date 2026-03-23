--TEST--
spawn_thread() - nested closures and array_map inside thread
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $result = await(spawn_thread(function() {
        $double = function($x) { return $x * 2; };
        $add = function($a, $b) { return $a + $b; };

        $arr = array_map($double, [1, 2, 3, 4, 5]);
        $sum = array_reduce($arr, $add, 0);
        return $sum;
    }));
    echo "sum: $result\n";
});
?>
--EXPECT--
sum: 30
