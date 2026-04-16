--TEST--
ThreadPool: submit() suspends the coroutine when the queue is full
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
    // 1 worker, queue capacity 1 — at most one task buffered at a time.
    $pool = new ThreadPool(1, 1);

    // t1 occupies the worker for ~150ms.
    $f1 = $pool->submit(fn() => (function() {
        $s = microtime(true);
        while (microtime(true) - $s < 0.15) {}
        return "t1";
    })());

    // Wait until the worker actually picked up t1 — freeing the 1-slot queue.
    while ($pool->getRunningCount() === 0) {
        delay(5);
    }

    // t2 fits into the now-empty buffer without blocking.
    $f2 = $pool->submit(fn() => "t2");

    // t3: buffer is full (t2 sits there), submit must suspend until
    // the worker picks up t2 — which only happens after t1 finishes.
    $start = microtime(true);
    $f3 = $pool->submit(fn() => "t3");
    $elapsed = microtime(true) - $start;

    echo ($elapsed > 0.03 ? "blocked" : "did not block") . "\n";

    $r1 = await($f1);
    $r2 = await($f2);
    $r3 = await($f3);
    echo "$r1,$r2,$r3\n";

    $pool->close();
});
?>
--EXPECT--
blocked
t1,t2,t3
