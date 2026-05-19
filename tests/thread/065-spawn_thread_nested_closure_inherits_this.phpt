--TEST--
spawn_thread() - nested closure created at runtime inherits $this from outer
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
    public int $n = 3;
    public function run(\Closure $boot): int {
        $t = spawn_thread(function(): int {
            $inner = fn(): int => $this->n * 10;
            return $inner();
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() { eval('class C { public int $n = 0; }'); };

spawn(function() use ($boot) {
    echo (new C)->run($boot), "\n";
});
?>
--EXPECT--
30
