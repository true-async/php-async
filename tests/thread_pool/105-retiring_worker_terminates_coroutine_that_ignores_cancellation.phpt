--TEST--
ThreadPool: a retiring worker terminates a bootloader coroutine that catches its cancellation, so reload() and close() complete
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

// A retry loop catches \Throwable, and with it the cancellation the retiring
// worker sends; the worker still has to end.
spawn(function () {
    $boot = static function (): void {
        spawn(static function (): void {
            while (true) {
                try {
                    delay(100);
                } catch (\Throwable $e) {
                }
            }
        });
    };

    $pool = new ThreadPool(1, 0, $boot);
    echo await($pool->submit(static fn () => 'task')), "\n";

    $pool->reload();
    echo await($pool->submit(static fn () => 'after reload')), "\n";

    $pool->close();

    // close() does not wait for the worker thread; the worker count says when it has left.
    for ($i = 0; $i < 50 && $pool->getWorkerCount() > 0; $i++) {
        delay(100);
    }

    echo "workers after close: ", $pool->getWorkerCount(), "\n";
});
?>
--EXPECTF--
task
%AWarning: 1 coroutine(s) did not end within async.shutdown_grace after the shutdown cancellation and are terminated in %s
%Aafter reload
%AWarning: 1 coroutine(s) did not end within async.shutdown_grace after the shutdown cancellation and are terminated in %s
%Aworkers after close: 0
