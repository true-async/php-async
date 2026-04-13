--TEST--
ThreadPool: exception class and message preserved across thread boundary
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

    $f = $pool->submit(fn() => throw new RuntimeException("boom from worker"));

    try {
        await($f);
        echo "unexpected success\n";
    } catch (\Throwable $e) {
        echo get_class($e) . "\n";
        echo $e->getMessage() . "\n";
    }

    $pool->close();
});
?>
--EXPECTF--
%SRuntimeException%S
%Sboom from worker%S
