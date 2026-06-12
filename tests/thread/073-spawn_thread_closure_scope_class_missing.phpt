--TEST--
spawn_thread() - class-scoped closure with no bootloader → clear scope error
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
    public static function makeWorker(): \Closure {
        return static function(): int { return 1; };
    }
}

spawn(function() {
    $t = spawn_thread(Demo::makeWorker());
    try {
        echo await($t), "\n";
        echo "ERROR: should not reach here\n";
    } catch (\Throwable $e) {
        echo $e->getMessage(), "\n";
    }
});
?>
--EXPECTF--
%ACannot restore closure scope: class "Demo" not found%A
