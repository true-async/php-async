--TEST--
spawn_thread() - first-class callable from static method (no $this)
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

class C {
    public static function answer(): int { return 42; }
}

$boot = function() {
    eval('class C { public static function answer(): int { return 42; } }');
};

spawn(function() use ($boot) {
    $t = spawn_thread(C::answer(...), bootloader: $boot);
    echo await($t), "\n";
});
?>
--EXPECT--
42
