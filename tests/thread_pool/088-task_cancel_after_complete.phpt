--TEST--
ThreadPool: cancel() on a task that already finished changes nothing
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

    $task = $pool->submit(static fn() => 'result');
    echo 'first await: ', await($task), "\n";

    $task->cancel();

    echo 'second await: ', await($task), "\n";

    // The worker must still be serving after a cancellation it could not use.
    echo 'next task: ', await($pool->submit(static fn() => 'alive')), "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
first await: result
second await: result
next task: alive
Done
