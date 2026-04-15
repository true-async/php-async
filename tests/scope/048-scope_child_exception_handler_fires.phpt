--TEST--
Scope: setChildScopeExceptionHandler() handler fires when a child scope coroutine throws
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers scope.c:1534-1558 — try_to_handle_exception() child scope handler invocation.

echo "start\n";

$parent = Scope::inherit()->asNotSafely();

$parent->setChildScopeExceptionHandler(function (Scope $scope, \Async\Coroutine $c, \Throwable $e) {
    echo "parent child-handler got: " . $e->getMessage() . "\n";
});

$child = Scope::inherit($parent)->asNotSafely();

$child->spawn(function () {
    throw new \RuntimeException("child boom");
});

// Let the child coroutine throw; exception should bubble to parent's child-handler.
suspend();
suspend();

echo "parent finished: " . ($parent->isFinished() ? "true" : "false") . "\n";
echo "end\n";

?>
--EXPECTF--
start
parent child-handler got: child boom
parent finished: true
end
