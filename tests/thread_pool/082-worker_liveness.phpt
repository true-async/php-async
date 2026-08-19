--TEST--
ThreadPool: getWorkerCount reports live workers, not the constructed size
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
    $pool = new ThreadPool(4);

    // A completed task proves every worker had a chance to start.
    await($pool->submit(fn() => 1));

    echo "alive: " . $pool->getWorkerCount() . "\n";

    $pool->close();

    // Workers leave as soon as the task channel closes. Bounded wait so a
    // build that never decrements reports its stale number instead of hanging.
    $deadline = microtime(true) + 5.0;
    while ($pool->getWorkerCount() > 0 && microtime(true) < $deadline) {
        delay(10);
    }

    echo "after close: " . $pool->getWorkerCount() . "\n";
    echo "Done\n";
});
?>
--EXPECT--
alive: 4
after close: 0
Done
