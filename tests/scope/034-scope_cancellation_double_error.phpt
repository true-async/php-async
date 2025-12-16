--TEST--
Scope cancellation with double-exception case in finally handlers execution
--DESCRIPTION--
This test triggers a double-exception case: first in the coroutine, and then in the onFinally handler.
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "start\n";

$scope = new \Async\Scope();

// Spawn coroutine with finally handlers
$coroutine_with_finally = $scope->spawn(function() {
    echo "coroutine with finally started\n";
    
    $coroutine = \Async\current_coroutine();

    $coroutine->onFinally(function() {
        echo "finally handler 3 executed\n";
        // This might throw during cancellation cleanup
        throw new \RuntimeException("Finally handler error");
    });
    
    suspend();
    echo "coroutine should not complete normally\n";
    return "normal_result";
});

// Spawn coroutine in child scope with finally handlers
$child_scope = \Async\Scope::inherit($scope);
$child_coroutine = $child_scope->spawn(function() {
    echo "child coroutine started\n";

    $coroutine = \Async\current_coroutine();
    some_function();
});

echo "coroutines with finally handlers spawned\n";

// Let coroutines start
suspend();

// Add scope-level finally handler
$scope->onFinally(function() {
    echo "scope finally handler executed\n";
});

echo "scope finally handler added\n";

// Cancel the parent scope - should trigger all finally handlers
echo "cancelling parent scope\n";
$scope->cancel(new \Async\CancellationError("Scope cancelled with finally"));

// Let cancellation propagate
suspend();

echo "end\n";

?>
--EXPECTF--
start
coroutines with finally handlers spawned
coroutine with finally started
child coroutine started
finally handler 3 executed

Fatal error: Uncaught Error: Call to undefined function some_function() in %s:%d
Stack trace:
#0 [internal function]: {closure:%s:%d}()
#1 {main}

Next RuntimeException: Finally handler error in %s:%d
Stack trace:
#0 [internal function]: {closure:{closure:%s:%d}:%d}(Object(Async\Coroutine))
#1 {main}
  thrown in %s on line %d