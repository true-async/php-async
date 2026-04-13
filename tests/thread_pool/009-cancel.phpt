--TEST--
ThreadPool: cancel rejects pending backlog, lets running tasks finish
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
use function Async\delay;

spawn(function() {
    $pool = new ThreadPool(1);

    // f1 blocks the sole worker long enough for f2 to queue up behind it.
    $f1 = $pool->submit(fn() => (function() {
        $start = microtime(true);
        while (microtime(true) - $start < 0.2) {}
        return "f1 done";
    })());

    // Give the worker a chance to actually pick up f1.
    while ($pool->getRunningCount() === 0) {
        delay(5);
    }

    // f2 goes into the backlog — worker is busy with f1.
    $f2 = $pool->submit(fn() => "f2 done");

    // cancel() must reject f2, f1 keeps running.
    $pool->cancel();

    try {
        echo await($f1) . "\n";
    } catch (\Throwable $e) {
        echo "f1 unexpectedly rejected: " . $e->getMessage() . "\n";
    }

    try {
        $r = await($f2);
        echo "f2 unexpectedly completed: $r\n";
    } catch (\Throwable $e) {
        echo "f2 cancelled\n";
    }

    echo "Done\n";
});
?>
--EXPECT--
f1 done
f2 cancelled
Done
