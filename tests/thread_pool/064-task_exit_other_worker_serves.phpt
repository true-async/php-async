--TEST--
ThreadPool: exit() in a task closes its worker, and the other worker serves the rest
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
    $pool = new ThreadPool(2);

    $t1 = $pool->submit(function() { exit(1); });
    $t2 = $pool->submit(function() { return "normal-1"; });
    $t3 = $pool->submit(function() { throw new RuntimeException("boom"); });
    $t4 = $pool->submit(function() { return 7 * 7; });

    try {
        await($t1);
    } catch (Async\ThreadTransferException $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }

    var_dump(await($t2));

    try {
        await($t3);
    } catch (\RuntimeException $e) {
        echo "t3: ", $e->getMessage(), "\n";
    }

    var_dump(await($t4));

    // The worker that called exit() rejects its task before it leaves the count.
    for ($i = 0; $i < 200 && $pool->getWorkerCount() > 1; $i++) {
        Async\delay(10);
    }
    var_dump($pool->getWorkerCount());

    $pool->close();
});
?>
--EXPECT--
Async\ThreadTransferException: ThreadPool worker terminated via exit()
string(8) "normal-1"
t3: boom
int(49)
int(1)
