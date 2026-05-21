--TEST--
Scope: disposeAfterTimeout() does not leak the cancellation exception
--DESCRIPTION--
Covers scope_timeout_coroutine_entry(). When the timer fires it creates a
fresh AsyncCancellation and hands it to ZEND_ASYNC_SCOPE_CANCEL. That call
must take ownership (transfer_error = true) — otherwise the freshly-created
exception is never released and leaks. Regression test for that leak;
only visible on a debug build's leak detector.
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\delay;

// Timer fires: a child is parked in delay() when disposeAfterTimeout() trips.
spawn(function () {
    $scope = Scope::inherit()->asNotSafely();
    $scope->spawn(function () {
        try { delay(5000); }
        catch (\Throwable $e) { /* cancelled by the timeout */ }
    });
    $scope->disposeAfterTimeout(20);
    for ($i = 0; $i < 12; $i++) {
        delay(20);
    }
    echo "fired: finished=", ($scope->isFinished() ? "yes" : "no"), "\n";
});

echo "done\n";
?>
--EXPECT--
done
fired: finished=yes
