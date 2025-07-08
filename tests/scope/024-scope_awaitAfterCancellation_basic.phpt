--TEST--
Scope: awaitAfterCancellation() - basic usage
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\timeout;
use Async\Scope;

echo "start\n";

// Test basic awaitAfterCancellation
$scope = Scope::inherit();

$coroutine1 = $scope->spawn(function() {
    echo "coroutine1 started\n";
    suspend();
    suspend();
    echo "coroutine1 finished\n";
    return "result1";
});

$coroutine2 = $scope->spawn(function() {
    echo "coroutine2 started\n";
    suspend();
    suspend(); 
    echo "coroutine2 finished\n";
    return "result2";
});

echo "spawned coroutines\n";

// Cancel the scope
$scope->cancel();
echo "scope cancelled\n";

// Await after cancellation from external context
$external = spawn(function() use ($scope) {
    echo "external waiting after cancellation\n";
    $scope->awaitAfterCancellation(null, timeout(1000));
    echo "awaitAfterCancellation completed\n";
});

$external->getResult();

echo "scope finished: " . ($scope->isFinished() ? "true" : "false") . "\n";
echo "scope closed: " . ($scope->isClosed() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECT--
start
spawned coroutines
coroutine1 started
coroutine2 started
scope cancelled
external waiting after cancellation
awaitAfterCancellation completed
scope finished: true
scope closed: true
end