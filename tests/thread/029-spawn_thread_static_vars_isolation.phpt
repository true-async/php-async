--TEST--
spawn_thread() - static variables in closure are isolated per thread
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
    // Each thread gets its own static variable scope
    $r1 = await(spawn_thread(function() {
        $counter = function() {
            static $n = 0;
            return ++$n;
        };
        return $counter() . ',' . $counter() . ',' . $counter();
    }));
    echo "thread1: " . $r1 . "\n";

    $r2 = await(spawn_thread(function() {
        $counter = function() {
            static $n = 0;
            return ++$n;
        };
        return $counter() . ',' . $counter();
    }));
    echo "thread2: " . $r2 . "\n";
});
?>
--EXPECT--
thread1: 1,2,3
thread2: 1,2
