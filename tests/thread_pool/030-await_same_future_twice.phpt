--TEST--
ThreadPool: awaiting the same future twice yields the same result
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
    $pool = new ThreadPool(1);

    $f = $pool->submit(fn() => 42);

    $a = await($f);
    $b = await($f);

    echo "$a,$b\n";
    $pool->close();
});
?>
--EXPECT--
42,42
