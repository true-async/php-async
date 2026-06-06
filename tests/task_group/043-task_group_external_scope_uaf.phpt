--TEST--
TaskGroup: externally supplied (PHP) Scope must survive until group destruction
--SKIPIF--
<?php
if (!class_exists("Async\\TaskSet")) die("skip TaskSet not available");
?>
--FILE--
<?php
// Regression: when a TaskGroup/TaskSet is constructed with an external PHP-level
// Scope (the `scope:` argument), the group used to keep only an event refcount on
// the scope, not the owner pin. The Scope object here is a temporary, so it is
// destroyed right after construction; the group keeps a raw `group->scope`
// pointer. The event refcount is shared with coroutine bookkeeping, so the last
// finishing coroutine drove the count to the dispose path and freed the scope
// while the group still referenced it -> use-after-free in task_group dtor.

use Async\Scope;
use Async\TaskSet;
use function Async\await;
use function Async\delay;
use function Async\spawn;

$ran = 0;

$main = spawn(function () use (&$ran): void {
    $taskSet = new TaskSet(concurrency: 3, scope: new Scope());

    $taskSet->spawn(function () use (&$ran): void { delay(5); $ran++; });
    $taskSet->spawn(function () use (&$ran): void { delay(5); $ran++; });

    $taskSet->close();
    $taskSet->awaitCompletion();
    echo "completed\n";

    // Destroying the group must not touch an already-freed scope.
    unset($taskSet);
    echo "destroyed\n";
});

await($main);
echo "ran=$ran\n";
echo "done\n";
?>
--EXPECT--
completed
destroyed
ran=2
done
