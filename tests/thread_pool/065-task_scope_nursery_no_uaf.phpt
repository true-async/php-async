--TEST--
ThreadPool: task scope is a nursery — un-awaited spawned coroutines are cancelled at task exit (snapshot UAF regression)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php
/*
 * Regression: a ThreadPool task deep-copies its closure (and every nested
 * closure) into a per-task snapshot arena. Before the fix the worker freed
 * that arena the moment the task body returned — while a coroutine the task
 * had spawned was still pending in the scheduler. The pending coroutine's
 * op_array lived in the just-freed arena, so running it dereferenced freed
 * memory (hard crash on the Windows debug heap; latent + ASAN-caught on
 * Linux).
 *
 * The fix runs each task body under its own per-task Scope in NOT-safe mode
 * (a nursery): coroutines the task spawns land in that scope, and on task
 * exit the scope is cancelled and awaited before the snapshot is freed — so
 * no spawned coroutine can outlive the snapshot. An un-awaited child is
 * therefore cancelled at task exit and never runs.
 */
use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

$pool = new ThreadPool(1);

$f = $pool->submit(function () {
    /* Un-awaited child — still pending when the task body returns. Before
     * the fix this crashed the worker (use-after-free of the freed snapshot
     * arena); now the task's nursery scope cancels it at exit, so its body
     * ("child") never runs and nothing is freed out from under it. */
    spawn(function () { echo "child\n"; });
    return 'task-done';
});

var_dump(await($f));

$pool->close();
echo "ok\n";
--EXPECT--
string(9) "task-done"
ok
