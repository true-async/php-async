--TEST--
TaskGroup: race() called after first task already failed synchronously rejects immediately
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;

// Covers task_group.c METHOD(race) L1452-1457: synchronous-reject branch
// when the first task in iteration order is already in TASK_STATE_ERROR
// at the moment race() is called.

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { throw new \RuntimeException("first-fail"); });
    $group->spawn(function() { return "second"; });

    $group->seal();

    // Let tasks run to completion before calling race().
    suspend();
    suspend();

    try {
        $group->race()->await();
        echo "no-throw\n";
    } catch (\RuntimeException $e) {
        echo "caught: ", $e->getMessage(), "\n";
    }
});

?>
--EXPECT--
caught: first-fail
