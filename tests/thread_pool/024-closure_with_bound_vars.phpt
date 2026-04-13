--TEST--
ThreadPool: closures transfer bound variables (use clause) via snapshot
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

    $prefix = "hello";
    $suffix = "world";
    $count = 3;

    $f = $pool->submit(function() use ($prefix, $suffix, $count) {
        return str_repeat("$prefix $suffix ", $count);
    });

    echo await($f) . "\n";
    $pool->close();
});
?>
--EXPECT--
hello world hello world hello world
