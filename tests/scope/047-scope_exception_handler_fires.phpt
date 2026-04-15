--TEST--
Scope: setExceptionHandler() handler actually fires and suppresses coroutine exception
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

// Covers scope.c:1563-1588 — try_to_handle_exception() scope exception handler invocation
// (the second try after child handler fails to match).

echo "start\n";

$scope = Scope::inherit()->asNotSafely();

$scope->setExceptionHandler(function (Scope $s, \Async\Coroutine $c, \Throwable $e) {
    echo "handler got: " . $e->getMessage() . "\n";
});

$scope->spawn(function () {
    throw new \RuntimeException("boom");
});

// Let the coroutine throw and the handler run.
suspend();
suspend();

echo "scope finished: " . ($scope->isFinished() ? "true" : "false") . "\n";
echo "end\n";

?>
--EXPECTF--
start
handler got: boom
scope finished: true
end
