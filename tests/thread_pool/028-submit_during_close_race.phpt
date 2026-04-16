--TEST--
ThreadPool: close() right after submit() still resolves the in-flight future
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
    $pool = new ThreadPool(2);

    $f = $pool->submit(fn() => "first");
    // Graceful close — worker must still drain the already-queued task.
    $pool->close();

    try {
        echo await($f) . "\n";
    } catch (\Throwable $e) {
        echo "unexpected rejection: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
first
