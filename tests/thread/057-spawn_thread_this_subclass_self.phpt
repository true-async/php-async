--TEST--
spawn_thread() - $this is a subclass instance; self::/parent:: behavior
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--DESCRIPTION--
In native PHP `self::X` resolves to the closure's *defining* class (Base).
The transferred closure carries its scope by name, so the worker matches
the local result: self::X is Base::X on both sides.
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

class Base {
    const X = 'base';
    public function make(): \Closure {
        return function(): string { return self::X; };
    }
}
class Child extends Base { const X = 'child'; }

$boot = function() {
    eval('class Base { const X = "base"; }');
    eval('class Child extends Base { const X = "child"; }');
};

spawn(function() use ($boot) {
    $c = (new Child)->make();
    echo "local: ", $c(), "\n";
    $t = spawn_thread($c, bootloader: $boot);
    echo "worker: ", await($t), "\n";
});
?>
--EXPECT--
local: base
worker: base
