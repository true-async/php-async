--TEST--
Concurrent scope cancellation and race conditions
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;
use function Async\await_all;

echo "start\n";

// Create multiple scopes
$scope1 = new \Async\Scope()->asNotSafely();
$scope2 = new \Async\Scope()->asNotSafely();
$scope3 = new \Async\Scope()->asNotSafely();

echo "multiple scopes created\n";

// Spawn coroutines in each scope
$coroutine1 = $scope1->spawn(function() {
    echo "coroutine1 started\n";
    suspend();
    suspend();
    echo "coroutine1 should not complete\n";
    return "result1";
});

$coroutine2 = $scope2->spawn(function() {
    echo "coroutine2 started\n";
    suspend();
    suspend();
    echo "coroutine2 should not complete\n";
    return "result2";
});

$coroutine3 = $scope3->spawn(function() {
    echo "coroutine3 started\n";
    suspend();
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
    $scope1->cancel(new \Async\AsyncCancellation("Concurrent cancel 1"));
});

$canceller2 = spawn(function() use ($scope2) {
    echo "canceller2 starting\n";
    $scope2->cancel(new \Async\AsyncCancellation("Concurrent cancel 2"));
});

$canceller3 = spawn(function() use ($scope3) {
    echo "canceller3 starting\n";
    $scope3->cancel(new \Async\AsyncCancellation("Concurrent cancel 3"));
});

echo "cancellers spawned\n";

await_all([$canceller1, $canceller2, $canceller3]);

echo "all cancellers completed\n";

// Verify all scopes are cancelled
echo "scope1 cancelled: " . ($scope1->isCancelled() ? "true" : "false") . "\n";
echo "scope2 cancelled: " . ($scope2->isCancelled() ? "true" : "false") . "\n";
echo "scope3 cancelled: " . ($scope3->isCancelled() ? "true" : "false") . "\n";

// Verify all coroutines are cancelled
$cancelled_count = 0;
foreach ([$coroutine1, $coroutine2, $coroutine3] as $index => $coroutine) {
    if($coroutine->isCancelled()) {
        $cancelled_count++;
        echo "coroutine" . ($index + 1) . " cancelled: " . $coroutine->getException()->getMessage() . "\n";
    } else {
        echo "coroutine" . ($index + 1) . " not cancelled\n";
    }
}

echo "cancelled coroutines: $cancelled_count\n";
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
all cancellers completed
scope1 cancelled: true
scope2 cancelled: true
scope3 cancelled: true
coroutine1 cancelled: Concurrent cancel 1
coroutine2 cancelled: Concurrent cancel 2
coroutine3 cancelled: Concurrent cancel 3
cancelled coroutines: 3
end