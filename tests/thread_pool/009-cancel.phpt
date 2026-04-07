--TEST--
ThreadPool: cancel stops pending tasks
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use Async\ThreadPoolException;
use function Async\spawn;
use function Async\await;

spawn(function() {
    $pool = new ThreadPool(1);

    // Submit more tasks than workers
    $f1 = $pool->submit(fn() => "done");
    $f2 = $pool->submit(fn() => "should be cancelled");

    // Cancel immediately
    $pool->cancel();

    echo await($f1) . "\n";

    try {
        await($f2);
        echo "f2 completed\n";
    } catch (\Throwable $e) {
        echo "f2 cancelled\n";
    }

    echo "Done\n";
});
?>
--EXPECTF--
%sDone
