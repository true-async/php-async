--TEST--
Scope cancellation with finally handlers execution
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
    
    $coroutine = \Async\currentCoroutine();

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

    $coroutine = \Async\currentCoroutine();
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
$scope->cancel(new \Async\CancellationException("Scope cancelled with finally"));

// Let cancellation propagate
suspend();

echo "end\n";

?>
--EXPECTF--
start
coroutines with finally handlers spawned
coroutine with finally started
child coroutine started
scope finally handler added
cancelling parent scope
finally handler 3 executed
finally handler 2 executed
finally handler 1 executed
child finally handler executed
scope finally handler executed
main coroutine %s: %s
child coroutine cancelled: Scope cancelled with finally
testing finally handler order in hierarchy
hierarchy coroutine started
cancelling parent scope in hierarchy
hierarchy coroutine finally
child scope finally
parent scope finally
hierarchy cancelled: Hierarchy cancel
end