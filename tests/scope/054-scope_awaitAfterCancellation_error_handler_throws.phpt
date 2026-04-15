--TEST--
Scope: awaitAfterCancellation() error handler may throw and its exception propagates
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers scope.c:754-757 — callback_resolve_when_zombie_completed() branch where
// the user-supplied error handler itself throws and the new exception is re-raised.

echo "start\n";

$scope = Scope::inherit()->asNotSafely();

$scope->spawn(function () {
    try {
        while (true) {
            suspend();
        }
    } catch (\Async\AsyncCancellation $e) {
        throw new \RuntimeException("initial");
    }
});

$external = spawn(function () use ($scope) {
    suspend();
    $scope->cancel(new \Async\AsyncCancellation("bye"));

    try {
        $scope->awaitAfterCancellation(function (\Throwable $e, Scope $s) {
            throw new \LogicException("handler replacement: " . $e->getMessage());
        });
        echo "no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
});

await($external);

echo "end\n";

?>
--EXPECTF--
start
caught: LogicException: handler replacement: initial
end
