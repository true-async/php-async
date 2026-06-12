--TEST--
spawn_thread() - static closure declared in a class keeps self::/static:: scope
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

class Demo {
    private const TAG = 'reached';

    public static function makeWorker(): \Closure {
        return static function(): string {
            return self::TAG . '|' . static::class;
        };
    }
}

$boot = function() {
    eval('class Demo { private const TAG = "reached"; }');
};

spawn(function() use ($boot) {
    $t = spawn_thread(Demo::makeWorker(), bootloader: $boot);
    echo await($t), "\n";
});
?>
--EXPECT--
reached|Demo
