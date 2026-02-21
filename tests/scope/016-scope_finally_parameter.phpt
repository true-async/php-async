--TEST--
Scope: finally() - finally handler receives scope parameter
--FILE--
<?php

use Async\Scope;
use function Async\await;

$scope = new Scope();
$coroutine = $scope->spawn(function() { 
    return "test"; 
});

$scope->finally(function($receivedScope) use ($scope) {
    echo "Finally handler received scope: " . 
         ($receivedScope === $scope ? "correct" : "incorrect") . "\n";
});

await($coroutine);
$scope->dispose();

?>
--EXPECT--
Finally handler received scope: correct