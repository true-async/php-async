--TEST--
Scope::awaitAfterCancellation() waits for the coroutines of child scopes too
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\delay;

$scope = (new Scope())->asNotSafely();
$child = Scope::inherit($scope);
$ended = [];

$scope->spawn(function () use (&$ended) {
    try {
        delay(5000);
    } catch (\Async\AsyncCancellation $e) {
        $ended[] = 'parent';
    }
});

$child->spawn(function () use (&$ended) {
    try {
        delay(5000);
    } catch (\Async\AsyncCancellation $e) {
        delay(300);
        $ended[] = 'child';
    }
});

spawn(function () use ($scope, &$ended) {
    delay(20);
    $scope->cancel();
    $scope->awaitAfterCancellation();
    echo "ended before the return: ", implode(', ', $ended), "\n";
});
?>
--EXPECT--
ended before the return: parent, child
