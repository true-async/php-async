--TEST--
Scope: Coroutine cancelling its own scope
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "start\n";

$scope = new \Async\Scope()->asNotSafely();

$self_cancelling = $scope->spawn(function() use ($scope) {
    echo "coroutine started\n";
    suspend(); // Let it start properly

    echo "coroutine cancelling its own scope\n";
    $scope->cancel(new \Async\AsyncCancellation("Self-cancellation"));
    echo "coroutine end\n";
});

echo "spawned coroutine\n";

// Let coroutine start and cancel scope
try {
    await($self_cancelling);
} catch (\Async\AsyncCancellation $e) {
    echo "caught cancellation in main\n";
}

echo "checking final state\n";
echo "scope cancelled: " . ($scope->isCancelled() ? "true" : "false") . "\n";
echo "coroutine cancelled: " . ($self_cancelling->isCancelled() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECT--
start
spawned coroutine
coroutine started
coroutine cancelling its own scope
coroutine end
caught cancellation in main
checking final state
scope cancelled: true
coroutine cancelled: true
end