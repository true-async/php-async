--TEST--
ThreadPool: a fatal in a coroutine-mode task delivers the cause and disposes the worker's channel trigger (no libuv loop leak)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--INI--
memory_limit=64M
--FILE--
<?php
/*
 * Regression: in coroutine mode the worker parks on the task-channel receive
 * (creating a uv_async trigger) while task coroutines run. A fatal in a task
 * re-raises zend_bailout() through that SUSPEND, which used to skip the
 * trigger's dispose — leaving an open uv_async that blocked uv_loop_close, so
 * the libuv loop leaked. The channel now disposes the trigger on bailout
 * (no-leak verified by LeakSanitizer).
 *
 * Also asserts the cause is delivered: pool_task_dispose detects the bailout
 * (no exception, UNDEF result) and rejects the future with the fatal message
 * instead of resolving to a silent null.
 */
use Async\ThreadPool;
use function Async\await;

$pool = new ThreadPool(1, 0, null, true); // coroutine mode

$f = $pool->submit(function () {
    $s = str_repeat('x', 500 * 1024 * 1024); // exceeds memory_limit -> bailout
    return strlen($s);
});

try {
    var_dump(await($f));
} catch (\Throwable $e) {
    printf("%s: %s\n", get_class($e),
        str_contains($e->getMessage(), 'memory size') ? 'memory exhausted' : 'other');
}
echo "done\n";
--EXPECTF--
%AAsync\ThreadTransferException: memory exhausted
done
