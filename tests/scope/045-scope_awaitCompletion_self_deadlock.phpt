--TEST--
Scope: awaitCompletion() from a coroutine that belongs to the same scope throws
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\await;
use function Async\timeout;

// Covers scope.c:300-307 — self-deadlock detection in awaitCompletion().

echo "start\n";

$scope = new Scope();

$inner = $scope->spawn(function () use (&$scope) {
    try {
        $scope->awaitCompletion(timeout(1000));
        echo "no error\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});

$external = spawn(function () use ($scope) {
    $scope->awaitCompletion(timeout(2000));
});
await($external);

echo "end\n";

?>
--EXPECTF--
start
caught: Cannot await completion of scope from a coroutine that belongs to the same scope or its children
end
