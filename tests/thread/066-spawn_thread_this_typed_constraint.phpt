--TEST--
spawn_thread() - $this with strict-typed property; transferred copy must keep types
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
    public int $n = 0;
    public function run(\Closure $boot): string {
        $t = spawn_thread(function(): string {
            $this->n = 7;
            try {
                $this->n = "string"; // TypeError expected (strict typed prop)
                return "no-type-check";
            } catch (\TypeError $e) {
                return "typed (n now {$this->n})";
            }
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
typed (n now 7)
