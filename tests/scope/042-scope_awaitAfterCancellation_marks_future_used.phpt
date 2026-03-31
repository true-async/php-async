--TEST--
Scope: awaitAfterCancellation() marks cancellation Future as used
--FILE--
<?php

use Async\Scope;
use Async\Future;
use Async\FutureState;
use function Async\spawn;
use function Async\await;
use function Async\delay;
use function Async\suspend;

echo "start\n";

$scope = Scope::inherit();
$scope->spawn(function() {
    try {
        delay(50);
    } catch (\Async\OperationCanceledException $e) {
        // expected
    }
});

// Let coroutine start
suspend();

$scope->cancel();

$state = new FutureState();
$future = new Future($state);

$external = spawn(function() use ($scope, $future) {
    $scope->awaitAfterCancellation(null, $future);
    echo "completed\n";
});

await($external);

// Destroy the future — must NOT trigger "Future was never used" warning
unset($future, $state);

echo "end\n";

?>
--EXPECT--
start
completed
end
