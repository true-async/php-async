--TEST--
Scope: awaitAfterCancellation() throws when scope is not cancelled
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers scope.c:370-372 — "Attempt to await a Scope that has not been cancelled".

echo "start\n";

$scope = new Scope();
$scope->spawn(function () {
    suspend();
});

$external = spawn(function () use ($scope) {
    try {
        $scope->awaitAfterCancellation();
        echo "no error\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});

await($external);

$scope->dispose();

echo "end\n";

?>
--EXPECTF--
start
caught: Attempt to await a Scope that has not been cancelled
end
