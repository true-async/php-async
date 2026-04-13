--TEST--
ThreadPool: count() via Countable interface returns pending+running total
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

    var_dump($pool instanceof Countable);
    echo count($pool) . "\n";

    $f = $pool->submit(fn() => 42);
    await($f);
    echo count($pool) . "\n";

    $pool->close();
});
?>
--EXPECT--
bool(true)
0
0
