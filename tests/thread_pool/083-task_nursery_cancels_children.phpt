--TEST--
ThreadPool: a task's un-awaited children die with the task in coroutine mode
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

// Both modes answer the same: the task scope is a nursery, so a child nobody
// awaited is cancelled when its task ends instead of running on past it. The
// pools are built one after another so the two reports cannot interleave.
spawn(function() {
    foreach ([true, false] as $coroutine) {
        $pool = new ThreadPool(workers: 1, coroutine: $coroutine);

        $task = $pool->submit(function() {
            spawn(function() {
                delay(300);
                echo "orphan child ran after its task\n";
            });

            return 'task done';
        });

        echo ($coroutine ? 'coroutine' : 'sync') . ': ' . await($task) . "\n";

        // Outlives the child's delay: a surviving child reports before close().
        delay(900);
        $pool->close();
    }

    echo "Done\n";
});
?>
--EXPECT--
coroutine: task done
sync: task done
Done
