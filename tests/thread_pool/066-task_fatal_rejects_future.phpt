--TEST--
ThreadPool: a fatal (OOM) in a sync task rejects its future with ThreadTransferException (no hang/UAF/leak)
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
 * Regression: a fatal in a sync task body re-raises zend_bailout() out of the
 * task coroutine and longjmps to the worker's bailout handler, past the normal
 * future-resolution. The handler must still reject the in-flight task's future
 * (already dequeued, so draining the channel doesn't reach it) — otherwise the
 * awaiter hangs.
 *
 * It must also free the per-task snapshot without a use-after-free: the loaded
 * op_array's name strings (function_name, filename) are materialized into normal
 * refcounted heap strings, so holders that outlive the snapshot arena — the
 * closure freed at request shutdown, and PG(last_error_file) — keep them alive
 * via refcount instead of dereferencing the freed arena.
 */
use Async\ThreadPool;
use function Async\await;

$pool = new ThreadPool(1);

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
