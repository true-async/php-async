--TEST--
spawn_thread() - closure that binds $this is rejected (no SEGV)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;

class C {
    protected string $var1 = "hello";
    public function run(): void {
        try {
            spawn_thread(function() {
                $var = $this->var1;
            });
            echo "FAIL: no exception\n";
        } catch (\Error $e) {
            echo $e->getMessage() . "\n";
        }
    }
}

spawn(function() {
    (new C)->run();
});
?>
--EXPECTF--
Cannot transfer closure to another thread: closure binds $this at %s:%d
