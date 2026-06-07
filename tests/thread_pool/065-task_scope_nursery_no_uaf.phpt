--TEST--
ThreadPool: a coroutine spawned but never awaited inside a sync task cannot outlive the per-task snapshot (UAF regression)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php
/*
 * Regression: a ThreadPool task deep-copies its closure (and every nested
 * closure) into a per-task snapshot arena. The worker used to free that arena
 * the moment the task body returned — while a coroutine the task had spawned
 * was still pending. The pending coroutine's op_array lived in the just-freed
 * arena, so running it dereferenced freed memory (Windows debug-heap crash;
 * ASAN-caught on Linux).
 *
 * The fix runs each task body as a coroutine in its own per-task Scope (a
 * nursery): on task exit the scope is cancelled and drained — awaited until
 * every spawned coroutine is physically disposed — before the snapshot is
 * freed. The only guaranteed invariant is that no spawned coroutine outlives
 * the snapshot; whether such a coroutine got to run at all is timing-dependent
 * and deliberately NOT asserted. The test passes iff there is no use-after-free
 * (caught by ASAN / the debug heap), the future resolves, and nothing hangs.
 */
use Async\ThreadPool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$pool = new ThreadPool(1);

$f = $pool->submit(function () {
    // Spawned, never awaited: still pending when the task body returns, so the
    // worker must cancel and drain it before freeing this task's snapshot.
    spawn(function () { delay(10000); });
    return 'task-done';
});

var_dump(await($f));

$pool->close();
echo "ok\n";
--EXPECT--
string(9) "task-done"
ok
