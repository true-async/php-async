--TEST--
spawn_thread() - class declaration in bootloader closure is rejected
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;

class Msg {
    public function __construct(public readonly string $text) {}
}

$bootloader = static function(): void {
    class Msg {
        public function __construct(public readonly string $text) {}
    }
};

spawn(function() use ($bootloader) {
    try {
        spawn_thread(
            task: static fn() => null,
            bootloader: $bootloader,
        );
        echo "FAIL: no exception\n";
    } catch (\Error $e) {
        echo $e->getMessage() . "\n";
    }
});
?>
--EXPECTF--
Cannot transfer closure to another thread: illegal class declaration at %s:%d
