--TEST--
Scope: onFinally() - multiple handlers execution
--FILE--
<?php

use Async\Scope;
use function Async\await;

$calls = [];
$scope = new Scope();

$coroutine = $scope->spawn(function() { 
    return "test"; 
});

$scope->onFinally(function() use (&$calls) {
    $calls[] = "first";
    echo "First finally handler\n";
});

$scope->onFinally(function() use (&$calls) {
    $calls[] = "second";
    echo "Second finally handler\n";
});

await($coroutine);
$scope->dispose();

echo "Handlers called: " . implode(", ", $calls) . "\n";

?>
--EXPECT--
First finally handler
Second finally handler
Handlers called: first, second