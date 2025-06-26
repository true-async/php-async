--TEST--
Scope: onFinally() - multiple handlers execution
--FILE--
<?php

use Async\Scope;
use function Async\await;

$calls = [];
$scope = new Scope();

$coroutine = $scope->spawn(function() { 
    echo "Spawned coroutine\n";
});

$scope->onFinally(function() {
    echo "First finally handler\n";
});

$scope->onFinally(function() {
    echo "Second finally handler\n";
});

await($coroutine);

echo "End of main coroutine\n";

?>
--EXPECT--
Spawned coroutine
End of main coroutine
First finally handler
Second finally handler