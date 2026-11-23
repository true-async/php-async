--TEST--
ThreadPool: in coroutine mode exit() in a task closes its worker; the other worker serves the rest
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
    $pool = new ThreadPool(2, coroutine: true);

    $exits = $pool->submit(function() { exit(2); });

    try {
        await($exits);
    } catch (Async\ThreadTransferException $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }

    var_dump(await($pool->submit(fn() => 6 * 7)));
    var_dump($pool->getWorkerCount());

    $pool->close();
});
?>
--EXPECT--
Async\ThreadTransferException: ThreadPool worker terminated via exit()
int(42)
int(1)
