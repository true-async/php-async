--TEST--
Scope: onFinally() - call when scope is already completed
--FILE--
<?php

use Async\Scope;
use function Async\await;

$scope = new Scope();
$coroutine = $scope->spawn(function() { 
    return "test"; 
});

await($coroutine);
$scope->dispose();

echo "Coroutine completed\n";

// Add finally handler to completed scope - should execute immediately
$scope->onFinally(function() {
    echo "Finally called on completed scope\n";
});

?>
--EXPECT--
Coroutine completed
Finally called on completed scope