--TEST--
ThreadPool: cancelling a task's future stops it in sync mode too
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
use function Async\delay;

spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: false);

    $task = $pool->submit(function() {
        echo "task started\n";
        delay(800);
        echo "task reached its end\n";
        return 'done';
    });

    delay(200);
    $task->cancel();

    try {
        await($task);
        echo "awaiter got a result\n";
    } catch (Async\AsyncCancellation $e) {
        echo "awaiter: cancelled\n";
    }

    delay(1500);
    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
task started
awaiter: cancelled
Done
