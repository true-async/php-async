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
 * and leak the libuv loop. The worker's bailout handler now disposes slot_event
 * (no-leak verified by LeakSanitizer). Also asserts the fatal cause reaches the
 * awaiter (reject, not a silent null).
 */
use Async\ThreadPool;
use function Async\await;

$pool = new ThreadPool(1, 0, null, true, 1); // coroutine mode, concurrency = 1

$f = $pool->submit(function () {
    $s = str_repeat('x', 500 * 1024 * 1024);
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
