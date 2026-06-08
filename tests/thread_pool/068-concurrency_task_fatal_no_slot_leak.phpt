--TEST--
ThreadPool: a fatal with a concurrency limit disposes the worker's slot_event trigger (no libuv loop leak)
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
 * Regression: with concurrency > 0 the worker parks on its slot_event trigger
 * at the limit. A fatal in a task longjmps past the worker's `done:` cleanup,
 * which disposes slot_event — leaving its open uv_async to block uv_loop_close
 * and leak the libuv loop. The worker's bailout handler now disposes slot_event.
 * Caught by LeakSanitizer.
 */
use Async\ThreadPool;
use function Async\await;

$pool = new ThreadPool(1, 0, null, true, 1); // coroutine mode, concurrency = 1

$f = $pool->submit(function () {
    $s = str_repeat('x', 500 * 1024 * 1024);
    return strlen($s);
});

var_dump(await($f));
echo "done\n";
--EXPECTF--
%ANULL
done
