--TEST--
ThreadPool: close rejects new submissions
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
use function Async\await;

spawn(function() {
    $pool = new ThreadPool(2);
    $pool->close();

    try {
        $pool->submit(fn() => 42);
        echo "ERROR: should have thrown\n";
    } catch (ThreadPoolException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    echo "isClosed: " . ($pool->isClosed() ? "yes" : "no") . "\n";
    echo "Done\n";
});
?>
--EXPECT--
Caught: ThreadPool is closed
isClosed: yes
Done
