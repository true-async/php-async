--TEST--
ThreadPool: cancelling the coroutine that awaits map() stops the batch
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
use function Async\delay;

spawn(function() {
    $pool = new ThreadPool(workers: 2, coroutine: true);
    $scope = new Scope();

    $scope->spawn(function() use ($pool) {
        $pool->map([1, 2, 3], function($item) {
            delay(3000);
            echo "item $item ran to the end\n";
            return $item;
        });
    });

    delay(300);
    $scope->cancel();

    // Outlives what was left of every task's delay.
    delay(3500);
    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
Done
