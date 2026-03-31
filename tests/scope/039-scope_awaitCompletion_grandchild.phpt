--TEST--
Scope: awaitCompletion() waits for grandchild scopes (cascading completion)
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
$childRef = null;
$grandchildRef = null;

$scope->spawn(function() use (&$results, &$childRef, &$grandchildRef) {
    $results[] = 'level_0';

    $childRef = Scope::inherit();
    $childRef->spawn(function() use (&$results, &$grandchildRef) {
        $results[] = 'level_1';

        $grandchildRef = Scope::inherit();
        $grandchildRef->spawn(function() use (&$results) {
            delay(50);
            $results[] = 'level_2';
        });
    });
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
results: level_0, level_1, level_2
finished: true
end
