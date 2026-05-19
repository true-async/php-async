--TEST--
spawn_thread() - arrow function fn() => $this->x
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
    public int $n = 7;
    public function run(\Closure $boot): int {
        $t = spawn_thread(fn(): int => $this->n * 2, bootloader: $boot);
        return await($t);
    }
}

$boot = function() { eval('class C { public int $n = 0; }'); };

spawn(function() use ($boot) {
    echo (new C)->run($boot), "\n";
});
?>
--EXPECT--
14
