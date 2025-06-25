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
try {
    $scope->onFinally(function() {
        throw new Exception("Error in finally handler");
    });
    
    $coroutine = $scope->spawn(function() { 
        return "test"; 
    });
    await($coroutine);
    $scope->dispose();
} catch (Exception $e) {
    echo "Caught finally handler error: " . $e->getMessage() . "\n";
}

?>
--EXPECT--
Caught expected error: argument must be callable
Caught finally handler error: Error in finally handler