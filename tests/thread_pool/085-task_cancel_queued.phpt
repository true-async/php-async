--TEST--
ThreadPool: a task cancelled while still queued never runs
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

    // The only worker is busy, so the second task waits in the channel.
    $busy = $pool->submit(function() { delay(400); return 'busy'; });
    while ($pool->getRunningCount() === 0) {
        delay(5);
    }

    $queued = $pool->submit(function() {
        echo "queued task ran anyway\n";
        return 'queued';
    });

    $queued->cancel();

    try {
        await($queued);
        echo "awaiter got a result\n";
    } catch (Async\AsyncCancellation $e) {
        echo "awaiter: cancelled\n";
    }

    echo 'busy: ', await($busy), "\n";

    // Long enough for the worker to have picked the queued task up.
    delay(300);
    echo 'completed: ', $pool->getCompletedCount(), "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
awaiter: cancelled
busy: busy
completed: 2
Done
