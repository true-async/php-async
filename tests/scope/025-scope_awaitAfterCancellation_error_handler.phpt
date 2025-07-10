--TEST--
Scope: awaitAfterCancellation() - with error handler
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\timeout;
use function Async\await;
use Async\Scope;

echo "start\n";

// Test awaitAfterCancellation with error handler
$scope = Scope::inherit();

$error_coroutine = $scope->spawn(function() {
    echo "error coroutine started\n";

    try {
        suspend(); // Suspend to simulate work
    } catch (\CancellationException $e) {
        echo "coroutine cancelled\n";
        suspend();
        throw new \RuntimeException("Coroutine error after cancellation");
    }
});

$normal_coroutine = $scope->spawn(function() {
    echo "normal coroutine started\n";
    suspend();
    suspend();
    echo "normal coroutine finished\n";
    return "normal_result";
});

echo "spawned coroutines\n";

// Await after cancellation with error handler
$external = spawn(function() use ($scope) {
    echo "external waiting with error handler\n";
    
    // Cancel the scope
    $scope->cancel();
    echo "scope cancel\n";
    suspend(); // Let cancellation propagate

    echo "awaitAfterCancellation with handler started\n";

    $scope->awaitAfterCancellation(
        function($error) {
            echo "error handler called: {$error->getMessage()}\n";
        },
        timeout(10)
    );
    
    echo "awaitAfterCancellation with handler completed\n";
});

await($external);

echo "scope finished: " . ($scope->isFinished() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECTF--
start
spawned coroutines
error coroutine started
normal coroutine started
external waiting with error handler
scope cancel
coroutine cancelled
awaitAfterCancellation with handler started
error handler called: Coroutine error after cancellation
awaitAfterCancellation with handler completed
scope finished: true
end