--TEST--
ThreadPool: a fatal in a coroutine-mode task disposes the worker's channel trigger (no libuv loop leak)
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
 * the libuv loop leaked. The channel now disposes the trigger on bailout.
 * Caught by LeakSanitizer; here we just assert no hang and clean completion.
 */
use Async\ThreadPool;
use function Async\await;

$pool = new ThreadPool(1, 0, null, true); // coroutine mode

$f = $pool->submit(function () {
    $s = str_repeat('x', 500 * 1024 * 1024); // exceeds memory_limit -> bailout
    return strlen($s);
});

var_dump(await($f));
echo "done\n";
--EXPECTF--
%ANULL
done
