--TEST--
Scope: awaitCompletion() throws AsyncCancellation on a cancelled (but not-yet-closed) scope
--FILE--
<?php

use Async\Scope;
use function Async\suspend;
use function Async\timeout;

// Covers exceptions.c:126-150 — async_throw_cancellation() with current_execute_data set,
// via the scope.c:295-297 branch ("If the scope is cancelled, throw cancellation exception").

echo "start\n";

$scope = Scope::inherit()->asNotSafely();
$scope->spawn(function () {
    try {
        while (true) {
            suspend();
        }
    } catch (\Async\AsyncCancellation $e) {
    }
});

suspend();

$scope->cancel(new \Async\AsyncCancellation("bye"));

var_dump($scope->isCancelled());
var_dump($scope->isClosed());

try {
    $scope->awaitCompletion(timeout(1000));
    echo "no error\n";
} catch (\Async\AsyncCancellation $e) {
    echo "caught: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
bool(true)
bool(false)
caught: The scope has been cancelled
end
