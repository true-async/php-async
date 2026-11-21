--TEST--
ThreadPool coroutine mode: a worker whose bootloader left a coroutine running retires on reload() and close()
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
    // A healthcheck timer the bootloader starts and never stops.
    $boot = static function (): void {
        spawn(static function (): void {
            while (true) {
                delay(100);
            }
        });
    };

    $pool = new ThreadPool(1, 0, $boot, true);
    echo await($pool->submit(static fn () => 'task')), "\n";

    $pool->reload();

    try {
        echo await($pool->submit(static fn () => 'after reload'), \Async\timeout(3000)), "\n";
    } catch (\Throwable $e) {
        echo "no task ran after reload within 3 s: ", get_class($e), "\n";
    }

    $pool->close();

    for ($i = 0; $i < 50 && $pool->getWorkerCount() > 0; $i++) {
        delay(100);
    }

    echo "workers after close: ", $pool->getWorkerCount(), "\n";
});
?>
--EXPECT--
task
after reload
workers after close: 0
