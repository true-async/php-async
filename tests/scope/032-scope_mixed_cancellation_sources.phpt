--TEST--
Mixed cancellation sources: scope cancellation + individual coroutine cancellation
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "start\n";

$scope = new \Async\Scope();

// Spawn multiple coroutines in the same scope
$coroutine1 = $scope->spawn(function() {
    echo "coroutine1 started\n";
    suspend();
    echo "coroutine1 should not complete\n";
    return "result1";
});

$coroutine2 = $scope->spawn(function() {
    echo "coroutine2 started\n";
    suspend();
    echo "coroutine2 should not complete\n";
    return "result2";
});

$coroutine3 = $scope->spawn(function() {
    echo "coroutine3 started\n";
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

// Check states after individual cancellation
echo "after individual cancel:\n";
echo "scope cancelled: " . ($scope->isCancelled() ? "true" : "false") . "\n";
echo "coroutine1 cancelled: " . ($coroutine1->isCancelled() ? "true" : "false") . "\n";
echo "coroutine2 cancelled: " . ($coroutine2->isCancelled() ? "true" : "false") . "\n";
echo "coroutine3 cancelled: " . ($coroutine3->isCancelled() ? "true" : "false") . "\n";

// Now cancel the entire scope
echo "cancelling entire scope\n";
$scope->cancel(new \Async\CancellationException("Scope cancel"));

// Check states after scope cancellation
echo "after scope cancel:\n";
echo "scope cancelled: " . ($scope->isCancelled() ? "true" : "false") . "\n";
echo "coroutine1 cancelled: " . ($coroutine1->isCancelled() ? "true" : "false") . "\n";
echo "coroutine2 cancelled: " . ($coroutine2->isCancelled() ? "true" : "false") . "\n";
echo "coroutine3 cancelled: " . ($coroutine3->isCancelled() ? "true" : "false") . "\n";

// Check results and exception messages
try {
    $result = $coroutine1->getResult();
    echo "coroutine1 should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "coroutine1 cancelled: " . $e->getMessage() . "\n";
}

try {
    $result = $coroutine2->getResult();
    echo "coroutine2 should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "coroutine2 cancelled: " . $e->getMessage() . "\n";
}

try {
    $result = $coroutine3->getResult();
    echo "coroutine3 should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "coroutine3 cancelled: " . $e->getMessage() . "\n";
}

// Test protected blocks with mixed cancellation
echo "testing protected blocks with mixed cancellation\n";

$protected_scope = new \Async\Scope();
$protected_coroutine = $protected_scope->spawn(function() {
    echo "protected coroutine started\n";
    
    \Async\protect(function() {
        echo "inside protected block\n";
        suspend();
        echo "protected block completed\n";
    });
    
    echo "after protected block\n";
    return "protected_result";
});

suspend(); // Enter protected block

// Try individual cancellation during protection
echo "cancelling protected coroutine individually\n";
$protected_coroutine->cancel(new \Async\CancellationException("Protected individual cancel"));

// Try scope cancellation during protection
echo "cancelling protected scope\n";
$protected_scope->cancel(new \Async\CancellationException("Protected scope cancel"));

suspend(); // Complete protected block

// Check which cancellation takes effect
try {
    $result = $protected_coroutine->getResult();
    echo "protected coroutine should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "protected coroutine cancelled: " . $e->getMessage() . "\n";
}

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
coroutine1 cancelled: Scope cancel
coroutine2 cancelled: Individual cancel
coroutine3 cancelled: Scope cancel
testing protected blocks with mixed cancellation
protected coroutine started
inside protected block
cancelling protected coroutine individually
cancelling protected scope
protected block completed
after protected block
protected coroutine cancelled: %s
end