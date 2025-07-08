--TEST--
Concurrent scope cancellation and race conditions
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "start\n";

// Create multiple scopes
$scope1 = new \Async\Scope();
$scope2 = new \Async\Scope();
$scope3 = new \Async\Scope();

echo "multiple scopes created\n";

// Spawn coroutines in each scope
$coroutine1 = $scope1->spawn(function() {
    echo "coroutine1 started\n";
    suspend();
    echo "coroutine1 should not complete\n";
    return "result1";
});

$coroutine2 = $scope2->spawn(function() {
    echo "coroutine2 started\n";
    suspend();
    echo "coroutine2 should not complete\n";
    return "result2";
});

$coroutine3 = $scope3->spawn(function() {
    echo "coroutine3 started\n";
    suspend();
    echo "coroutine3 should not complete\n";
    return "result3";
});

echo "coroutines spawned\n";

// Let coroutines start
suspend();

// Spawn concurrent cancellation operations
$canceller1 = spawn(function() use ($scope1) {
    echo "canceller1 starting\n";
    suspend(); // Small delay
    echo "canceller1 cancelling scope1\n";
    $scope1->cancel(new \Async\CancellationException("Concurrent cancel 1"));
    echo "canceller1 finished\n";
});

$canceller2 = spawn(function() use ($scope2) {
    echo "canceller2 starting\n";
    suspend(); // Small delay
    echo "canceller2 cancelling scope2\n";
    $scope2->cancel(new \Async\CancellationException("Concurrent cancel 2"));
    echo "canceller2 finished\n";
});

$canceller3 = spawn(function() use ($scope3) {
    echo "canceller3 starting\n";
    suspend(); // Small delay
    echo "canceller3 cancelling scope3\n";
    $scope3->cancel(new \Async\CancellationException("Concurrent cancel 3"));
    echo "canceller3 finished\n";
});

echo "cancellers spawned\n";

// Let cancellers start and complete
suspend();
suspend();

// Check that all cancellers completed
$canceller1->getResult();
$canceller2->getResult();
$canceller3->getResult();

echo "all cancellers completed\n";

// Verify all scopes are cancelled
echo "scope1 cancelled: " . ($scope1->isCancelled() ? "true" : "false") . "\n";
echo "scope2 cancelled: " . ($scope2->isCancelled() ? "true" : "false") . "\n";
echo "scope3 cancelled: " . ($scope3->isCancelled() ? "true" : "false") . "\n";

// Verify all coroutines are cancelled
$cancelled_count = 0;
foreach ([$coroutine1, $coroutine2, $coroutine3] as $index => $coroutine) {
    try {
        $result = $coroutine->getResult();
        echo "coroutine" . ($index + 1) . " unexpectedly succeeded\n";
    } catch (\Async\CancellationException $e) {
        echo "coroutine" . ($index + 1) . " cancelled: " . $e->getMessage() . "\n";
        $cancelled_count++;
    }
}

echo "cancelled coroutines: $cancelled_count\n";

// Test rapid cancellation/creation cycle
echo "testing rapid scope operations\n";
$rapid_scopes = [];
for ($i = 0; $i < 5; $i++) {
    $rapid_scopes[] = new \Async\Scope();
}

// Cancel them all quickly
foreach ($rapid_scopes as $index => $scope) {
    $scope->cancel(new \Async\CancellationException("Rapid cancel $index"));
}

$rapid_cancelled = 0;
foreach ($rapid_scopes as $scope) {
    if ($scope->isCancelled()) {
        $rapid_cancelled++;
    }
}

echo "rapid cancelled scopes: $rapid_cancelled / " . count($rapid_scopes) . "\n";

echo "end\n";

?>
--EXPECTF--
start
multiple scopes created
coroutines spawned
coroutine1 started
coroutine2 started
coroutine3 started
cancellers spawned
canceller1 starting
canceller2 starting
canceller3 starting
canceller1 cancelling scope1
canceller2 cancelling scope2
canceller3 cancelling scope3
canceller1 finished
canceller2 finished
canceller3 finished
all cancellers completed
scope1 cancelled: true
scope2 cancelled: true
scope3 cancelled: true
coroutine1 cancelled: Concurrent cancel 1
coroutine2 cancelled: Concurrent cancel 2
coroutine3 cancelled: Concurrent cancel 3
cancelled coroutines: 3
testing rapid scope operations
rapid cancelled scopes: 5 / 5
end