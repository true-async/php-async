--TEST--
spawn_thread() - $this with no bootloader → clear error, no SEGV
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
    public function run(): string {
        try {
            $t = spawn_thread(function(): int { return $this->n; });
            return (string) await($t);
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }
}

spawn(function() {
    echo (new C)->run(), "\n";
});
?>
--EXPECTF--
%Aclass "C" not found%A
