--TEST--
ThreadPool: submit() $this with no bootloader → clear error, no SEGV
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

class C {
    public int $n = 1;

    public function go(ThreadPool $pool): string {
        try {
            return (string) await($pool->submit(function() {
                return $this->n;
            }));
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }
}

spawn(function() {
    $pool = new ThreadPool(2);
    echo (new C)->go($pool), "\n";
    $pool->close();
});
?>
--EXPECTF--
%Aclass "C" not found%A
