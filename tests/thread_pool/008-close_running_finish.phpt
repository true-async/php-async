--TEST--
ThreadPool: close lets running tasks finish
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

    $f1 = $pool->submit(fn() => "task1");
    $f2 = $pool->submit(fn() => "task2");

    $pool->close();

    // Already submitted tasks should complete
    echo await($f1) . "\n";
    echo await($f2) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
task1
task2
Done
