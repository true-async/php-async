--TEST--
Scope: awaitCompletion() waits for deeply nested spawn() chains
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

// Coroutine spawns coroutine spawns coroutine — all in the same scope
$scope->spawn(function() use (&$results) {
    $results[] = 'a';

    spawn(function() use (&$results) {
        $results[] = 'b';
        delay(20);

        spawn(function() use (&$results) {
            $results[] = 'c';
            delay(20);

            spawn(function() use (&$results) {
                delay(20);
                $results[] = 'd';
            });
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
results: a, b, c, d
finished: true
end
