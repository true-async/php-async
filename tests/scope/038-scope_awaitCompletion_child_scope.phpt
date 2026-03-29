--TEST--
Scope: awaitCompletion() waits for child scopes created via Scope::inherit()
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
$childScopeRef = null;

$scope->spawn(function() use (&$results, &$childScopeRef) {
    $results[] = 'parent_start';

    // Create child scope — inherits from ZEND_ASYNC_CURRENT_SCOPE = $scope
    $childScopeRef = Scope::inherit();

    $childScopeRef->spawn(function() use (&$results) {
        delay(50);
        $results[] = 'child_scope_coroutine_1';
    });

    $childScopeRef->spawn(function() use (&$results) {
        delay(100);
        $results[] = 'child_scope_coroutine_2';
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
echo "parent finished: " . ($scope->isFinished() ? "true" : "false") . "\n";
echo "child finished: " . ($childScopeRef->isFinished() ? "true" : "false") . "\n";
echo "end\n";

?>
--EXPECT--
start
scope completed
results: parent_start, parent_end, child_scope_coroutine_1, child_scope_coroutine_2
parent finished: true
child finished: true
end
