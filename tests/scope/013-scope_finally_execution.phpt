--TEST--
Scope: finally() - basic execution of finally handler
--FILE--
<?php

use Async\Scope;
use function Async\await;

$called = false;
$scope = new Scope();

$coroutine = $scope->spawn(function() {
    echo "Coroutine started\n";
});

// You should understand that this handler will be invoked in a different coroutine,
// so you cannot rely on the exact timing of when it will happen.
$scope->finally(function() {
    echo "Finally handler executed\n";
});

await($coroutine);
$scope->dispose();

?>
--EXPECT--
Coroutine started
Finally handler executed