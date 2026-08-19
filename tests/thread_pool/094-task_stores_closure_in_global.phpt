--TEST--
ThreadPool: a closure a task left in $GLOBALS is callable from the next task
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

// The opcodes of a materialized closure used to live in the snapshot arena, so
// a closure that outlived its task was a body in freed memory. Calling it from
// a later task is the sharpest form of that.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    await($pool->submit(static function() {
        $GLOBALS['later'] = static fn(int $n): int => $n * 3;
        return 'stored';
    }));

    // Allocation pressure in between, so the freed arena block is handed out
    // again: without that the stale body often still reads as itself.
    await($pool->submit(static function() {
        $junk = [];
        for ($i = 0; $i < 20000; $i++) { $junk[] = "filler $i"; }
        return count($junk);
    }));

    $result = await($pool->submit(static function() {
        $fn = $GLOBALS['later'] ?? null;
        return $fn === null ? 'gone' : $fn(14);
    }));

    var_dump($result);

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
int(42)
Done
