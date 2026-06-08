--TEST--
ThreadPool: an exception thrown in the bootloader body propagates to awaiting tasks
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

spawn(function() {
    $boot = function() { throw new \RuntimeException("boot failed!"); };
    $pool = new ThreadPool(1, 0, $boot);
    try {
        await($pool->submit(fn() => 1));
        echo "no exception\n";
    } catch (\Throwable $e) {
        // The bootloader failure reaches the awaiter either as the real
        // RuntimeException (submit landed before the worker closed the channel)
        // or as Async\ThreadTransferException carrying the same message (submit
        // raced the close). Both deliver the true cause, so assert on it.
        echo $e->getMessage(), "\n";
    }
    $pool->close();
});
?>
--EXPECT--
boot failed!
