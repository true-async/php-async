--TEST--
ThreadPool: a task declaring a nested closure leaves nothing behind in the worker
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

// DECLARE_LAMBDA_FUNCTION memoizes the Closure it creates and pins it in
// EG(lambda_cache), which is drained when the request ends. A worker's request
// outlives every task it runs, so the pinned object held the task's nested body
// at refcount 1 and destroy_op_array left the whole task op_array behind:
// about 930 bytes per task, growing without a ceiling.
//
// The memo is off in a materialized op_array, so the same literal evaluated
// twice inside one task yields two objects, as it does at the top level of a
// script and as it did before the memo existed. That is asserted here because
// it is the price of the fix, not a detail.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    $task = static function() { $f = static function () { return 1; }; return $f(); };
    $probe = static function() { return memory_get_usage(); };

    for ($i = 0; $i < 200; $i++) {
        await($pool->submit($task));
    }

    $base = await($pool->submit($probe));

    for ($i = 0; $i < 2000; $i++) {
        await($pool->submit($task));
    }

    $grown = await($pool->submit($probe)) - $base;

    // Before the fix this run grew by about 1.9 MB.
    var_dump($grown < 100000);

    var_dump(await($pool->submit(static function() {
        $a = [];
        for ($i = 0; $i < 2; $i++) { $a[] = static function () { return 1; }; }
        return $a[0] === $a[1];
    })));

    $pool->close();
    echo "Done\n";
});

?>
--EXPECT--
bool(true)
bool(false)
Done
