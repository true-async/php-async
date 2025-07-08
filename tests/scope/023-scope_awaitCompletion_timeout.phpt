--TEST--
Scope: awaitCompletion() - timeout handling
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\timeout;
use Async\Scope;

echo "start\n";

// Test awaitCompletion with timeout
$scope = Scope::inherit();

$long_running = $scope->spawn(function() {
    echo "long running coroutine started\n";
    suspend();
    suspend();
    suspend();
    echo "long running coroutine finished\n";
    return "delayed_result";
});

echo "spawned long running coroutine\n";

// Try to await completion with short timeout
$external = spawn(function() use ($scope) {
    echo "external waiting with timeout\n";
    try {
        $scope->awaitCompletion(timeout(50));
        echo "ERROR: Should have timed out\n";
    } catch (\Async\TimeoutException $e) {
        echo "caught timeout as expected\n";
    }
});

$external->getResult();

// Cancel the long running coroutine to clean up
$long_running->cancel();

echo "scope finished: " . ($scope->isFinished() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECT--
start
spawned long running coroutine
external waiting with timeout
long running coroutine started
caught timeout as expected
scope finished: true
end