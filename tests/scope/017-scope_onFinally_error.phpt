--TEST--
Scope: onFinally() - error handling validation
--FILE--
<?php

use Async\Scope;
use function Async\await;

$scope = new Scope();

// Test 1: Invalid callable
try {
    $scope->onFinally("not_a_callable");
} catch (TypeError $e) {
    echo "Caught expected error: argument must be callable\n";
}

// Test 2: Error in finally handler
$scope->onFinally(function() {
    throw new Exception("Error in finally handler");
});

$coroutine = $scope->spawn(function() {
    return "test";
});

await($coroutine);
$scope->dispose();

?>
--EXPECT--
Caught expected error: argument must be callable
Caught finally handler error: Error in finally handler