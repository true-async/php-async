--TEST--
Scope: finally() - multiple handlers execution
--FILE--
<?php

use Async\Scope;
use function Async\await;

$calls = [];
$scope = new Scope();

$coroutine = $scope->spawn(function() { 
    echo "Spawned coroutine\n";
});

$scope->finally(function() {
    echo "First finally handler\n";
});

$scope->finally(function() {
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