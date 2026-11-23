--TEST--
ThreadPool: exit() in the bootloader rejects tasks with the exit message, not an earlier error's
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
        @trigger_error("an earlier warning", E_USER_WARNING);
        exit(0);
    });

    try {
        await($pool->submit(fn () => 1));
    } catch (Async\ThreadTransferException $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }

    $pool->close();
});
?>
--EXPECT--
Async\ThreadTransferException: ThreadPool worker terminated via exit()
