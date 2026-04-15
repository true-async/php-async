--TEST--
Scope: awaitAfterCancellation() from a coroutine that belongs to the same scope throws
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers scope.c:374-381 — self-deadlock detection in awaitAfterCancellation().

echo "start\n";

$scope = Scope::inherit()->asNotSafely();

$inner = $scope->spawn(function () use (&$scope) {
    // Wait until the scope is cancelled from outside, then trigger self-deadlock path.
    try {
        suspend();
    } catch (\Async\AsyncCancellation $e) {
        try {
            $scope->awaitAfterCancellation();
            echo "no error\n";
        } catch (\Throwable $e) {
            echo "caught: " . $e->getMessage() . "\n";
        }
    }
});

suspend();
$scope->cancel(new \Async\AsyncCancellation("bye"));
suspend();
suspend();

echo "end\n";

?>
--EXPECTF--
start
caught: Cannot await completion of scope from a coroutine that belongs to the same scope or its children
end
