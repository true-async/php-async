--TEST--
ThreadPool: a fatal (OOM) in a sync task rejects its future with ThreadTransferException (not a hang)
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
 * Regression: a fatal error in a sync task body re-raises zend_bailout() out of
 * the task coroutine and longjmps to the worker's bailout handler, past the
 * normal future-resolution. The handler must still reject the in-flight task's
 * future (it was already dequeued, so draining the channel does not reach it) —
 * otherwise the awaiter waits forever.
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
