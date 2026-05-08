--TEST--
spawn_thread() - class declaration in task closure is rejected
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;

spawn(function() {
    try {
        spawn_thread(static function(): string {
            class Greeter {
                public function hello(): string { return 'hi'; }
            }
            return (new Greeter())->hello();
        });
        echo "FAIL: no exception\n";
    } catch (\Error $e) {
        echo $e->getMessage() . "\n";
    }
});
?>
--EXPECTF--
Cannot transfer closure to another thread: illegal class declaration at %s:%d
