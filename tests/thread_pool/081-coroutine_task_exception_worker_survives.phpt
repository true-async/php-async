--TEST--
ThreadPool: a coroutine-mode task that throws leaves its worker serving
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

// Under test: the OS thread that ran the failing task keeps taking work. One
// worker makes it exact — if that thread exits, the next Future never settles.
$pool = new Async\ThreadPool(workers: 1, coroutine: true);

try {
    Async\await($pool->submit(static fn(): never => throw new RuntimeException('boom')));
} catch (RuntimeException $e) {
    echo "rejected: ", $e->getMessage(), "\n";
}

try {
    echo "next: ", Async\await($pool->submit(static fn() => 'served'), Async\timeout(2000)), "\n";
} catch (Throwable $e) {
    echo "next: ", $e::class, "\n";
}

$pool->close();
echo "Done\n";
?>
--EXPECT--
rejected: boom
next: served
Done
