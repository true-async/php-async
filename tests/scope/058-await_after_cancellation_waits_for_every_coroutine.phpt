--TEST--
Scope::awaitAfterCancellation() returns after the last coroutine of the cancelled scope ends, not the first
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\delay;

$scope = (new Scope())->asNotSafely();
$ended = [];

$scope->spawn(function () use (&$ended) {
    try {
        delay(5000);
    } catch (\Async\AsyncCancellation $e) {
        delay(50);
        $ended[] = 'fast';
    }
});

$scope->spawn(function () use (&$ended) {
    try {
        delay(5000);
    } catch (\Async\AsyncCancellation $e) {
        delay(300);
        $ended[] = 'slow';
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
ended before the return: fast, slow
