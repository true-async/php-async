--TEST--
Mixed cancellation sources: scope cancellation + individual coroutine cancellation
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "start\n";

$scope = new \Async\Scope()->asNotSafely();

// Spawn multiple coroutines in the same scope
$coroutine1 = $scope->spawn(function() {
    echo "coroutine1 started\n";
    suspend();
    suspend();
    echo "coroutine1 should not complete\n";
    return "result1";
});

$coroutine2 = $scope->spawn(function() {
    echo "coroutine2 started\n";
    suspend();
    suspend();
    echo "coroutine2 should not complete\n";
    return "result2";
});

$coroutine3 = $scope->spawn(function() {
    echo "coroutine3 started\n";
    suspend();
    suspend();
    echo "coroutine3 should not complete\n";
    return "result3";
});

echo "coroutines spawned in scope\n";

// Let coroutines start
suspend();

// Cancel individual coroutine first
echo "cancelling coroutine2 individually\n";
$coroutine2->cancel(new \Async\CancellationException("Individual cancel"));

// Let cancellation propagate
suspend();

// Check states after individual cancellation
echo "after individual cancel:\n";
echo "scope cancelled: " . ($scope->isCancelled() ? "true" : "false") . "\n";
echo "coroutine1 cancelled: " . ($coroutine1->isCancelled() ? "true" : "false") . "\n";
echo "coroutine2 cancelled: " . ($coroutine2->isCancelled() ? "true" : "false") . "\n";
echo "coroutine3 cancelled: " . ($coroutine3->isCancelled() ? "true" : "false") . "\n";

// Now cancel the entire scope
echo "cancelling entire scope\n";
$scope->cancel(new \Async\CancellationException("Scope cancel"));

suspend(); // Let cancellation propagate

// Check states after scope cancellation
echo "after scope cancel:\n";
echo "scope cancelled: " . ($scope->isCancelled() ? "true" : "false") . "\n";
echo "coroutine1 cancelled: " . ($coroutine1->isCancelled() ? "true" : "false") . "\n";
echo "coroutine2 cancelled: " . ($coroutine2->isCancelled() ? "true" : "false") . "\n";
echo "coroutine3 cancelled: " . ($coroutine3->isCancelled() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECTF--
start
coroutines spawned in scope
coroutine1 started
coroutine2 started
coroutine3 started
cancelling coroutine2 individually
after individual cancel:
scope cancelled: false
coroutine1 cancelled: false
coroutine2 cancelled: true
coroutine3 cancelled: false
cancelling entire scope
after scope cancel:
scope cancelled: true
coroutine1 cancelled: true
coroutine2 cancelled: true
coroutine3 cancelled: true
end