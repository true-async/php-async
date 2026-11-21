--TEST--
ThreadPool: a retiring worker ends a coroutine that catches its cancellation, so close() and reload() return
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
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
            try {
                while (true) {
                    try {
                        delay(100);
                    } catch (\Throwable $e) {
                    }
                }
            } finally {
                echo "finally ran\n";
            }
        });
    };

    $pool = new ThreadPool(1, 0, $boot);
    echo await($pool->submit(static fn () => 'task')), "\n";

    $pool->reload();
    echo await($pool->submit(static fn () => 'after reload')), "\n";

    $pool->close();
    echo "closed\n";
});
?>
--EXPECTF--
task
%Afinally ran
%Aafter reload
%Afinally ran
%Aclosed
