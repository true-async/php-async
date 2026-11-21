--TEST--
ThreadPool: a child a task leaves behind that catches its cancellation is terminated, and the worker takes the next task
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
--INI--
async.shutdown_grace=300
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

spawn(function () {
    $pool = new ThreadPool(1);

    echo await($pool->submit(static function () {
        spawn(static function () {
            while (true) {
                try {
                    delay(100);
                } catch (\Throwable $e) {
                }
            }
        });
        delay(20);

        return 'first task returned';
    })), "\n";

    try {
        echo await($pool->submit(static fn () => 'second task ran'), \Async\timeout(3000)), "\n";
    } catch (\Throwable $e) {
        echo "second task did not run within 3 s: ", get_class($e), "\n";
    }

    $pool->close();
    echo "closed\n";
});
?>
--EXPECT--
first task returned
second task ran
closed
