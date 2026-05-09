--TEST--
await_all_or_fail() — already-errored Future as a trigger must throw cleanly
--DESCRIPTION--
Regression test for the segfault that occurred when async_await_futures
encountered an already-closed errored Future during iteration. The synchronous
REPLAY path used to call ZEND_ASYNC_RESUME_WITH_ERROR with a NULL coroutine
(because zend_async_resume_when had not yet been called for that callback),
which dereferenced through coroutine->waker → SIGSEGV.

The fix stashes the exception on the await context and rethrows it from
async_await_futures after the iteration loop, so the awaiter sees the original
exception via the normal throw path.
--FILE--
<?php

use Async\Future;
use Async\FutureState;
use function Async\spawn;
use function Async\await_all;
use function Async\await_all_or_fail;

echo "start\n";

$st1 = new FutureState();
$st2 = new FutureState();
$st3 = new FutureState();
$f1 = new Future($st1);
$f2 = new Future($st2);
$f3 = new Future($st3);

spawn(fn() => $st1->complete(1));
spawn(fn() => $st2->error(new RuntimeException("boom")));
spawn(fn() => $st3->complete(3));

$awaiter = spawn(function() use ($f1, $f2, $f3) {
    try {
        await_all_or_fail([$f1, $f2, $f3]);
        echo "no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e::class . " " . $e->getMessage() . "\n";
    }
});

await_all([$awaiter]);
echo "end\n";
?>
--EXPECT--
start
caught: RuntimeException boom
end
