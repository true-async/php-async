--TEST--
ThreadPool: exit()/die() in a task rejects its future, the worker ends and the pool keeps serving
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
    // Single worker: exit() ends the worker; the pool replaces it, so the next task still runs.
    $pool = new ThreadPool(1);
    $a = $pool->submit(function() { exit(5); });
    $b = $pool->submit(function() { return 42; });
    try {
        await($a);
    } catch (Async\ThreadTransferException $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
    var_dump(await($b));
    $pool->close();
});
?>
--EXPECT--
Async\ThreadTransferException: ThreadPool worker terminated via exit()
int(42)
