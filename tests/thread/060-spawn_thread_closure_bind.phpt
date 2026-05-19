--TEST--
spawn_thread() - Closure::bind to an object before spawn
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
    public int $n = 99;
}

$boot = function() {
    eval('class C { public int $n = 0; }');
};

spawn(function() use ($boot) {
    $bare = function(): int { return $this->n; };
    $bound = Closure::bind($bare, new C(), C::class);
    $t = spawn_thread($bound, bootloader: $boot);
    echo await($t), "\n";
});
?>
--EXPECT--
99
