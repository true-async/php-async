--TEST--
ThreadPool: getPendingCount, getRunningCount, count
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

    echo "Before: count=" . $pool->count() . "\n";

    $f1 = $pool->submit(fn() => "a");
    $f2 = $pool->submit(fn() => "b");

    echo "After submit: count=" . $pool->count() . "\n";

    await($f1);
    await($f2);

    echo "After await: count=" . $pool->count() . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECTF--
Before: count=0
After submit: count=%d
After await: count=0
Done
