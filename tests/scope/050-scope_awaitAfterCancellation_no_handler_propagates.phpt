--TEST--
Scope: awaitAfterCancellation() without an error handler propagates the coroutine exception
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers scope.c:721-725 — callback_resolve_when_zombie_completed() no-handler branch.

echo "start\n";

$scope = Scope::inherit()->asNotSafely();

$scope->spawn(function () {
    try {
        while (true) {
            suspend();
        }
    } catch (\Async\AsyncCancellation $e) {
        throw new \RuntimeException("propagated error");
    }
});

$external = spawn(function () use ($scope) {
    suspend();
    $scope->cancel(new \Async\AsyncCancellation("bye"));

    try {
        $scope->awaitAfterCancellation();
        echo "no exception\n";
    } catch (\Throwable $e) {
        echo "propagated: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
});

await($external);

echo "end\n";

?>
--EXPECTF--
start
propagated: RuntimeException: propagated error
end
