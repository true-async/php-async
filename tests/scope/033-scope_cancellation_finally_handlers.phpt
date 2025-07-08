--TEST--
Scope cancellation with finally handlers execution
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "start\n";

$scope = new \Async\Scope();

// Spawn coroutine with finally handlers
$coroutine_with_finally = $scope->spawn(function() {
    echo "coroutine with finally started\n";
    
    $coroutine = \Async\Coroutine::getCurrent();
    
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
    
    $coroutine = \Async\Coroutine::getCurrent();
    
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

// Check execution order and exception handling
try {
    $result = $coroutine_with_finally->getResult();
    echo "main coroutine should not succeed\n";
} catch (\Async\CompositeException $e) {
    echo "main coroutine CompositeException with " . count($e->getErrors()) . " errors\n";
    foreach ($e->getErrors() as $error) {
        echo "composite error: " . get_class($error) . ": " . $error->getMessage() . "\n";
    }
} catch (\Async\CancellationException $e) {
    echo "main coroutine cancelled: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "main coroutine unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

try {
    $result = $child_coroutine->getResult();
    echo "child coroutine should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "child coroutine cancelled: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "child coroutine unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Test finally handler order in cancelled scope hierarchy
echo "testing finally handler order in hierarchy\n";

$parent_scope = new \Async\Scope();
$child_scope2 = \Async\Scope::inherit($parent_scope);

$parent_scope->onFinally(function() {
    echo "parent scope finally\n";
});

$child_scope2->onFinally(function() {
    echo "child scope finally\n";
});

$hierarchy_coroutine = $child_scope2->spawn(function() {
    echo "hierarchy coroutine started\n";
    
    \Async\Coroutine::getCurrent()->onFinally(function() {
        echo "hierarchy coroutine finally\n";
    });
    
    suspend();
    return "hierarchy_result";
});

suspend(); // Let it start

echo "cancelling parent scope in hierarchy\n";
$parent_scope->cancel(new \Async\CancellationException("Hierarchy cancel"));

try {
    $result = $hierarchy_coroutine->getResult();
    echo "hierarchy should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "hierarchy cancelled: " . $e->getMessage() . "\n";
}

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