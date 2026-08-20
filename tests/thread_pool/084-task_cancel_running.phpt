--TEST--
ThreadPool: cancelling a task's future stops the task on the worker
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
    $pool = new ThreadPool(workers: 1, coroutine: true);

    $task = $pool->submit(function() {
        echo "task started\n";
        delay(800);
        echo "task reached its end\n";
        return 'done';
    });

    // The task is inside delay() by now, so the cancellation has to travel to
    // the worker to have any effect.
    delay(200);
    $task->cancel();

    try {
        await($task);
        echo "awaiter got a result\n";
    } catch (Async\AsyncCancellation $e) {
        echo "awaiter: cancelled\n";
    }

    // Outlives what was left of the task's own delay.
    delay(1500);
    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
task started
awaiter: cancelled
Done
