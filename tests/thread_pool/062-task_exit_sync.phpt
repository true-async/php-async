--TEST--
ThreadPool: exit()/die() in a task closes its worker; the last worker closes the pool and rejects the queued tasks
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

spawn(function() {
    // Single worker: exit() closes it, and with no worker left the queued task is rejected.
    $pool = new ThreadPool(1);
    $a = $pool->submit(function() { exit(5); });
    $b = $pool->submit(function() { return 42; });

    foreach ([$a, $b] as $future) {
        try {
            var_dump(await($future));
        } catch (Async\ThreadTransferException $e) {
            echo get_class($e), ": ", $e->getMessage(), "\n";
        }
    }

    var_dump($pool->getWorkerCount());
    $pool->close();
});
?>
--EXPECT--
Async\ThreadTransferException: ThreadPool worker terminated via exit()
Async\ThreadTransferException: ThreadPool worker terminated via exit()
int(0)
