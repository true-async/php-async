--TEST--
TaskGroup: gc_get handler walks tasks in PENDING / RUNNING / ERROR states
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers task_group.c:518-547 — task_group_object_gc() walking task entries
// across all three task_state_t values (PENDING, RUNNING, ERROR).

echo "start\n";

$group = new TaskGroup(concurrency: 1);

// 1. Add a task that immediately throws — when its coroutine ends with
//    an exception the entry transitions to TASK_STATE_ERROR.
$group->spawn(function () {
    throw new \RuntimeException("first");
});

// 2. A short suspend-then-finish task — RUNNING for a few ticks.
$group->spawn(function () {
    suspend();
    suspend();
});

// 3. Concurrency=1 means extra tasks are queued as TASK_STATE_PENDING.
$group->spawn(function () {});
$group->spawn(function () {});

// Yield once: task #1 runs and throws, task #2 starts and suspends.
suspend();

// Force GC while task #2 is in RUNNING and tasks #3/#4 are PENDING.
gc_collect_cycles();

// Drain the rest.
suspend();
suspend();
suspend();

echo "end\n";

?>
--EXPECTF--
start
end
