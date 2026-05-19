--TEST--
spawn_thread() - $this is a subclass instance; self::/parent:: behavior
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--DESCRIPTION--
Documents the current scope semantics for transferred closures.
In native PHP `self::X` resolves to the closure's *defining* class (Base).
Across spawn_thread the worker scope is currently set to Z_OBJCE($this),
so `self::X` resolves to Child::X. Same closure called locally still
gives Base::X — this test pins both behaviors so a future fix is
visible as an EXPECT diff.
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
worker: child
