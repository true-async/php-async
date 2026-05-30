--TEST--
ThreadPool: exit()/die() in a task resolves its future to null and the worker keeps serving (no crash)
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
    // Single worker: exit() must NOT crash and must NOT kill the worker —
    // the worker's request survives, so the next task still runs.
    $pool = new ThreadPool(1);
    $a = $pool->submit(function() { exit(5); });
    $b = $pool->submit(function() { return 42; });
    var_dump(await($a));
    var_dump(await($b));
    $pool->close();
});
?>
--EXPECT--
NULL
int(42)
