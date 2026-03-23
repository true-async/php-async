--TEST--
spawn_thread() - calling built-in functions inside closure
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
        $arr = [3, 1, 4, 1, 5, 9];
        sort($arr);
        return implode(',', $arr);
    }));
    echo $result . "\n";

    $result2 = await(spawn_thread(function() {
        return strtoupper(str_repeat('ab', 3));
    }));
    echo $result2 . "\n";

    $result3 = await(spawn_thread(function() {
        return array_map(function($x) { return $x * 2; }, [1, 2, 3]);
    }));
    echo implode(',', $result3) . "\n";
});
?>
--EXPECT--
1,1,3,4,5,9
ABABAB
2,4,6
