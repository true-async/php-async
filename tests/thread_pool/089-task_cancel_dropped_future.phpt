--TEST--
ThreadPool: an awaiter that goes away takes its task with it
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\Scope;
use Async\ThreadPool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

// Nobody calls cancel() on the task here. The scope cancels the coroutine that
// was awaiting it, the future loses its last reference, and that is all the
// worker ever hears about it.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);
    $scope = new Scope();

    $scope->spawn(function() use ($pool) {
        $task = $pool->submit(function() {
            echo "task started\n";
            delay(800);
            echo "task reached its end\n";
            return 'done';
        });

        await($task);
    });

    delay(200);
    $scope->cancel();

    delay(1500);
    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
task started
Done
