--TEST--
TaskGroup: any() called after all tasks already failed synchronously rejects with CompositeException
--FILE--
<?php

use Async\TaskGroup;
use Async\CompositeException;
use function Async\spawn;
use function Async\suspend;

// Covers task_group.c METHOD(any) L1495-1500: synchronous-all-errors branch
// — when any() is called after all tasks already errored, reject immediately
// with a composite exception.

spawn(function() {
    $group = new TaskGroup();
    $group->spawn(function() { throw new \RuntimeException("fail1"); });
    $group->spawn(function() { throw new \LogicException("fail2"); });
    $group->seal();

    // Let tasks run to completion before calling any().
    suspend();
    suspend();

    try {
        $group->any()->await();
        echo "no-throw\n";
    } catch (CompositeException $e) {
        echo "count: ", count($e->getExceptions()), "\n";
    }
});

?>
--EXPECT--
count: 2
