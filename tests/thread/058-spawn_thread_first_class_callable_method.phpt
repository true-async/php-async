--TEST--
spawn_thread() - first-class callable from instance method ($obj->method(...))
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
    public function __construct(public int $n) {}
    public function dbl(): int { return $this->n * 2; }
}

$boot = function() {
    eval('class C { public function __construct(public int $n = 0) {} public function dbl(): int { return $this->n * 2; } }');
};

spawn(function() use ($boot) {
    $obj = new C(21);
    $t = spawn_thread($obj->dbl(...), bootloader: $boot);
    echo await($t), "\n";
});
?>
--EXPECT--
42
