--TEST--
Fiber: Fiber::suspend() inside finally after shutdown surfaces FiberError
--DESCRIPTION--
Mirror of Zend/tests/fibers/suspend-in-force-close-fiber-after-shutdown.phpt,
copied into ext/async/tests so the async coroutine path keeps surfacing the
uncaught FiberError instead of swallowing it. Regression guard for the
async_coroutine_finalize fix that surfaces exceptions for fiber-backed
coroutines even when the coroutine handle is still alive (the fiber awaits
via yield_event, not the handle, so no late await() can ever observe it).
--FILE--
<?php

$fiber = new Fiber(function (): void {
    try {
        Fiber::suspend();
    } finally {
        Fiber::suspend();
    }
});

$fiber->start();

echo "done\n";

?>
--EXPECTF--
done

Fatal error: Uncaught FiberError: Cannot suspend in a force-closed fiber in %ssuspend_in_finally_after_shutdown.php:%d
Stack trace:
#0 %ssuspend_in_finally_after_shutdown.php(%d): Fiber::suspend()
#1 [internal function]: {closure:%s:%d}()
#2 {main}
  thrown in %ssuspend_in_finally_after_shutdown.php on line %d
