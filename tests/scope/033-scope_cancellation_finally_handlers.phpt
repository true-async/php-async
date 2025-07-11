--TEST--
Scope cancellation with finally handlers execution
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "start\n";

$scope = new \Async\Scope()->asNotSafely();

// Spawn coroutine with finally handlers
$coroutine_with_finally = $scope->spawn(function() {
    echo "coroutine with finally started\n";
    
    $coroutine = \Async\currentCoroutine();
    
    $coroutine->onFinally(function() {
        echo "finally handler 1 executed\n";
    });
    
    $coroutine->onFinally(function() {
        echo "finally handler 2 executed\n";
        return "finally_cleanup";
    });
    
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
    
    $coroutine->onFinally(function() {
        echo "child finally handler executed\n";
    });
    
    suspend();
    echo "child should not complete\n";
    return "child_result";
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
finally handler 1 executed
finally handler 2 executed
finally handler 3 executed
child finally handler executed
scope finally handler executed

Fatal error: Uncaught RuntimeException:%s
%a