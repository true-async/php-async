--TEST--
ThreadPool: exit() in a coroutine the bootloader spawned, while the bootloader waits, closes the worker
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

spawn(function () {
    $pool = new ThreadPool(1, 0, function () {
        Async\spawn(function () {
            Async\delay(5);
            exit(0);
        });
        Async\delay(100);
    });

    try {
        await($pool->submit(fn () => 1));
        echo "task ran\n";
    } catch (Async\ThreadTransferException $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }

    $pool->close();
});
?>
--EXPECT--
Async\ThreadTransferException: ThreadPool worker terminated via exit()
