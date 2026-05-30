--TEST--
ThreadPool: $this-bound bootloader that can't load on the worker rejects the task with the real error
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

// A $this-bound bootloader carries an object whose class is NOT defined on the
// worker (and cannot be — the bootloader is what would declare it). The worker
// must propagate the real transfer error to the awaiter, not a generic
// "cancelled before execution" cancellation.
class C {
    public int $n = 5;

    public function boot(): void {
        // never reached: loading $this fails before the body runs
        echo "boot ran\n";
    }
}

spawn(function() {
    $c = new C();
    $pool = new ThreadPool(2, 0, $c->boot(...));
    try {
        await($pool->submit(fn() => 1));
        echo "no exception\n";
    } catch (\Throwable $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
    $pool->close();
});
?>
--EXPECTF--
%ACannot load transferred object: class "C" not found%A
