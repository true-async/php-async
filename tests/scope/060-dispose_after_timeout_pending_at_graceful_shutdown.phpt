--TEST--
Scope::disposeAfterTimeout() whose timer fires after a graceful shutdown freed the main scope does not touch it
--INI--
async.shutdown_grace=300
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\delay;

register_shutdown_function(function () {
    echo "process ends\n";
});

$scope = new Scope();
$scope->spawn(function () {
    while (true) {
        delay(50);
    }
});

spawn(function () use ($scope) {
    delay(20);
    // The timer outlives the shutdown below: it fires once the main scope is gone.
    $scope->disposeAfterTimeout(100);
    throw new \RuntimeException('boom');
});
?>
--EXPECTF--
%AFatal error: Uncaught RuntimeException: boom%A
process ends
