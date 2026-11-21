--TEST--
ThreadPool coroutine mode: cancel() after close() still ends a running task that catches its cancellation
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
use function Async\delay;

spawn(function () {
    $pool = new ThreadPool(1, 0, null, true);

    $pool->submit(static function () {
        while (true) {
            try {
                delay(100);
            } catch (\Throwable $e) {
            }
        }
    });

    delay(200);
    $pool->close();
    delay(100);
    $pool->cancel();

    for ($i = 0; $i < 50 && $pool->getWorkerCount() > 0; $i++) {
        delay(100);
    }

    echo "workers after cancel: ", $pool->getWorkerCount(), "\n";
});
?>
--EXPECTF--
%Aworkers after cancel: 0
