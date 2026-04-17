--TEST--
ThreadPool: getPendingCount, getRunningCount, getCompletedCount
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

    echo "Before: pending=" . $pool->getPendingCount()
        . " running=" . $pool->getRunningCount()
        . " completed=" . $pool->getCompletedCount() . "\n";

    $f1 = $pool->submit(fn() => "a");
    $f2 = $pool->submit(fn() => "b");

    await($f1);
    await($f2);

    echo "After await: pending=" . $pool->getPendingCount()
        . " running=" . $pool->getRunningCount()
        . " completed=" . $pool->getCompletedCount() . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
Before: pending=0 running=0 completed=0
After await: pending=0 running=0 completed=2
Done
