--TEST--
ThreadPool: submit after cancel throws ThreadPoolException
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use Async\ThreadPoolException;
use function Async\spawn;

spawn(function() {
    $pool = new ThreadPool(2);
    $pool->cancel();

    try {
        $pool->submit(fn() => 42);
        echo "no exception\n";
    } catch (ThreadPoolException $e) {
        echo "rejected: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
rejected: ThreadPool is closed
