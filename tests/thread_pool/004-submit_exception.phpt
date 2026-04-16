--TEST--
ThreadPool: task throws exception — future rejects
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

    $future = $pool->submit(function() {
        throw new \RuntimeException("task failed");
    });

    try {
        await($future);
        echo "ERROR: should have thrown\n";
    } catch (\Throwable $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
Caught: task failed
Done
