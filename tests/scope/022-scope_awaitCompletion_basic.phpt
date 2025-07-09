--TEST--
Scope: awaitCompletion() - basic usage
--FILE--
<?php

use function Async\spawn;
use function Async\timeout;
use Async\Scope;
use function Async\await;

echo "start\n";

// Test 1: Basic awaitCompletion with successful completion
$scope = Scope::inherit();

$coroutine1 = $scope->spawn(function() {
    echo "coroutine1 running\n";
    return "result1";
});

$coroutine2 = $scope->spawn(function() {
    echo "coroutine2 running\n";
    return "result2";
});

echo "spawned coroutines\n";

// Await completion from external scope
$external = spawn(function() use ($scope) {
    echo "external waiting for scope completion\n";
    $scope->awaitCompletion(timeout(1000));
    echo "scope completed\n";
});

echo "awaiting external\n";
await($external);

echo "verifying results\n";
echo "coroutine1 result: " . $coroutine1->getResult() . "\n";
echo "coroutine2 result: " . $coroutine2->getResult() . "\n";
echo "scope finished: " . ($scope->isFinished() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECT--
start
spawned coroutines
awaiting external
coroutine1 running
coroutine2 running
external waiting for scope completion
scope completed
verifying results
coroutine1 result: result1
coroutine2 result: result2
scope finished: true
end