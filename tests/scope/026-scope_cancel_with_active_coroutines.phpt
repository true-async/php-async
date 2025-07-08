--TEST--
Scope: cancel() - comprehensive cancellation with active coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use Async\Scope;

echo "start\n";

// Test comprehensive cancellation behavior
$scope = Scope::inherit();

$coroutine1 = $scope->spawn(function() {
    echo "coroutine1 started\n";
    try {
        suspend();
        suspend();
        echo "coroutine1 should not reach here\n";
        return "result1";
    } catch (\Async\CancellationException $e) {
        echo "coroutine1 caught cancellation: " . $e->getMessage() . "\n";
        throw $e;
    }
});

$coroutine2 = $scope->spawn(function() {
    echo "coroutine2 started\n";
    try {
        suspend();
        suspend();
        echo "coroutine2 should not reach here\n";
        return "result2";
    } catch (\Async\CancellationException $e) {
        echo "coroutine2 caught cancellation: " . $e->getMessage() . "\n";
        throw $e;
    }
});

echo "spawned coroutines\n";

// Let coroutines start
suspend();

echo "cancelling scope\n";
$scope->cancel(new \Async\CancellationException("Custom cancellation message"));

echo "verifying cancellation\n";
echo "scope finished: " . ($scope->isFinished() ? "true" : "false") . "\n";
echo "scope closed: " . ($scope->isClosed() ? "true" : "false") . "\n";

// Verify coroutines are cancelled
echo "coroutine1 cancelled: " . ($coroutine1->isCancelled() ? "true" : "false") . "\n";
echo "coroutine2 cancelled: " . ($coroutine2->isCancelled() ? "true" : "false") . "\n";

// Try to spawn in cancelled scope (should fail)
try {
    $scope->spawn(function() {
        return "should_not_work";
    });
    echo "ERROR: Should not be able to spawn in closed scope\n";
} catch (Error $e) {
    echo "caught expected error: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
spawned coroutines
coroutine1 started
coroutine2 started
cancelling scope
coroutine1 caught cancellation: Custom cancellation message
coroutine2 caught cancellation: Custom cancellation message
verifying cancellation
scope finished: true
scope closed: true
coroutine1 cancelled: true
coroutine2 cancelled: true
caught expected error: %s
end