--TEST--
TaskGroup: all() called after tasks already failed synchronously rejects via CompositeException
--FILE--
<?php

use Async\TaskGroup;
use Async\CompositeException;
use function Async\spawn;
use function Async\suspend;

// Covers task_group.c METHOD(all) L1406-1421 synchronous-settled branch:
// when all tasks in the group are already completed/errored at the moment
// all() is called, the future is resolved/rejected immediately without
// waiting. Exercises both the reject-with-composite path (L1408-1411).

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { throw new \RuntimeException("sync-fail-1"); });
    $group->spawn(function() { throw new \LogicException("sync-fail-2"); });

    $group->seal();

    // Let the spawned tasks run to completion before calling all().
    suspend();
    suspend();

    try {
        $group->all()->await();
        echo "no-throw\n";
    } catch (CompositeException $e) {
        echo "count: ", count($e->getExceptions()), "\n";
        foreach ($e->getExceptions() as $err) {
            echo get_class($err), ": ", $err->getMessage(), "\n";
        }
    }
});

?>
--EXPECT--
count: 2
RuntimeException: sync-fail-1
LogicException: sync-fail-2
