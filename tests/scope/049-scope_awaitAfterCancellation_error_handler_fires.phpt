--TEST--
Scope: awaitAfterCancellation() error handler is invoked when a coroutine finishes with an exception
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers scope.c:728-760 — callback_resolve_when_zombie_completed() error-handler branch
// inside awaitAfterCancellation(errorHandler).

echo "start\n";

$scope = Scope::inherit()->asNotSafely();

$scope->spawn(function () {
    try {
        while (true) {
            suspend();
        }
    } catch (\Async\AsyncCancellation $e) {
        throw new \RuntimeException("after-cancel error");
    }
});

$external = spawn(function () use ($scope) {
    suspend();
    $scope->cancel(new \Async\AsyncCancellation("bye"));

    $scope->awaitAfterCancellation(function (\Throwable $e, Scope $s) {
        echo "error handler: " . $e->getMessage() . "\n";
    });

    echo "external done\n";
});

await($external);

echo "end\n";

?>
--EXPECTF--
start
error handler: after-cancel error
external done
end
