--TEST--
spawn_thread() - returns error code on non-ZTS build
--SKIPIF--
<?php
if (PHP_ZTS) die('skip non-ZTS only test');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;

spawn(function() {
    try {
        spawn_thread(function() {
            echo "should not run\n";
        });
        echo "no throw\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
caught: spawn_thread() requires a Thread-Safe (ZTS) PHP build
