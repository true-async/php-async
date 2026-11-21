--TEST--
Scope::dispose() ends a coroutine that catches its cancellation, and the scope is not finished before that
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\delay;

$scope = new Scope();
$scope->spawn(function () {
    try {
        while (true) {
            try {
                delay(50);
            } catch (\Throwable $e) {
            }
        }
    } finally {
        echo "finally ran\n";
    }
});

spawn(function () use ($scope) {
    delay(20);
    $scope->dispose();
    echo "after dispose: finished=", var_export($scope->isFinished(), true), "\n";

    for ($i = 0; $i < 100 && !$scope->isFinished(); $i++) {
        delay(50);
    }

    echo "later: finished=", var_export($scope->isFinished(), true), "\n";
});
?>
--EXPECTF--
after dispose: finished=false
%Afinally ran
%Alater: finished=true
