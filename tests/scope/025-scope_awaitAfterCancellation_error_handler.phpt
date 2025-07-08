--TEST--
Scope: awaitAfterCancellation() - with error handler
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\timeout;
use Async\Scope;

echo "start\n";

// Test awaitAfterCancellation with error handler
$scope = Scope::inherit();

$error_coroutine = $scope->spawn(function() {
    echo "error coroutine started\n";
    suspend();
    throw new \RuntimeException("Coroutine error");
});

$normal_coroutine = $scope->spawn(function() {
    echo "normal coroutine started\n";
    suspend();
    suspend();
    echo "normal coroutine finished\n";
    return "normal_result";
});

echo "spawned coroutines\n";

// Cancel the scope
$scope->cancel();
echo "scope cancelled\n";

// Await after cancellation with error handler
$external = spawn(function() use ($scope) {
    echo "external waiting with error handler\n";
    
    $errors_handled = [];
    
    $scope->awaitAfterCancellation(
        function($errors) use (&$errors_handled) {
            echo "error handler called\n";
            echo "errors count: " . count($errors) . "\n";
            foreach ($errors as $error) {
                echo "error: " . $error->getMessage() . "\n";
                $errors_handled[] = $error->getMessage();
            }
        },
        timeout(1000)
    );
    
    echo "awaitAfterCancellation with handler completed\n";
    return $errors_handled;
});

$handled_errors = $external->getResult();
echo "handled errors count: " . count($handled_errors) . "\n";

echo "scope finished: " . ($scope->isFinished() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECTF--
start
spawned coroutines
error coroutine started
normal coroutine started
scope cancelled
external waiting with error handler
error handler called
errors count: %d
error: %s
awaitAfterCancellation with handler completed
handled errors count: %d
scope finished: true
end