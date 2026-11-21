--TEST--
ThreadPool coroutine mode: cancel() terminates a task that catches its cancellation, and its future is rejected
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
    $pool = new ThreadPool(1, 0, null, true);

    $future = $pool->submit(static function () {
        while (true) {
            try {
                delay(100);
            } catch (\Throwable $e) {
            }
        }

        return 'never';
    });

    delay(200);
    $pool->cancel();

    try {
        $result = await($future, \Async\timeout(3000));
        echo "await returned ", var_export($result, true), "\n";
    } catch (\Async\AsyncCancellation $e) {
        echo "await threw ", get_class($e), "\n";
    } catch (\Throwable $e) {
        echo "await threw ", get_class($e), ": ", $e->getMessage(), "\n";
    }

    for ($i = 0; $i < 50 && $pool->getWorkerCount() > 0; $i++) {
        delay(100);
    }

    echo "workers after cancel: ", $pool->getWorkerCount(), "\n";
});
?>
--EXPECTF--
%Aawait threw Async\AsyncCancellation
%Aworkers after cancel: 0
