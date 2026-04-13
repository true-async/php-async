--TEST--
ThreadPool: cancel on idle pool (all tasks already drained)
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

    // Drain a task first so the pool has worker state but nothing pending.
    echo await($pool->submit(fn() => "warm")) . "\n";

    // Now cancel an idle pool — no exceptions, no hangs.
    $pool->cancel();
    echo "cancelled\n";
    var_dump($pool->isClosed());
});
?>
--EXPECT--
warm
cancelled
bool(true)
