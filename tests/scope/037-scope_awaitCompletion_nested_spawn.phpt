--TEST--
Scope: awaitCompletion() waits for coroutines spawned inside scope's coroutines
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\await;
use function Async\delay;
use function Async\timeout;

echo "start\n";

$scope = Scope::inherit();
$results = [];

$scope->spawn(function() use (&$results) {
    $results[] = 'parent_start';

    // Nested spawn() — should go into the same scope via ZEND_ASYNC_CURRENT_SCOPE
    spawn(function() use (&$results) {
        delay(50);
        $results[] = 'child_1';
    });

    spawn(function() use (&$results) {
        delay(100);
        $results[] = 'child_2';
    });

    $results[] = 'parent_end';
});

$external = spawn(function() use ($scope) {
    try {
        $scope->awaitCompletion(timeout(2000));
        echo "scope completed\n";
    } catch (\Async\OperationCanceledException $e) {
        echo "ERROR: timed out\n";
    }
});

await($external);

echo "results: " . implode(', ', $results) . "\n";
echo "finished: " . ($scope->isFinished() ? "true" : "false") . "\n";
echo "end\n";

?>
--EXPECT--
start
scope completed
results: parent_start, parent_end, child_1, child_2
finished: true
end
