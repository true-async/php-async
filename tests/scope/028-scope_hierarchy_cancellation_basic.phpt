--TEST--
Basic scope hierarchy cancellation propagation
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "start\n";

// Create parent scope
$parent_scope = new \Async\Scope();

// Create child scopes
$child1_scope = \Async\Scope::inherit($parent_scope);
$child2_scope = \Async\Scope::inherit($parent_scope);

echo "scopes created\n";

// Spawn coroutines in each scope
$parent_coroutine = $parent_scope->spawn(function() {
    echo "parent coroutine started\n";
    suspend();
    echo "parent coroutine should not complete\n";
    return "parent_result";
});

$child1_coroutine = $child1_scope->spawn(function() {
    echo "child1 coroutine started\n";
    suspend();
    echo "child1 coroutine should not complete\n";
    return "child1_result";
});

$child2_coroutine = $child2_scope->spawn(function() {
    echo "child2 coroutine started\n";
    suspend();
    echo "child2 coroutine should not complete\n";
    return "child2_result";
});

echo "coroutines spawned\n";

// Let coroutines start
suspend();

// Check initial states
echo "parent scope cancelled: " . ($parent_scope->isCancelled() ? "true" : "false") . "\n";
echo "child1 scope cancelled: " . ($child1_scope->isCancelled() ? "true" : "false") . "\n";
echo "child2 scope cancelled: " . ($child2_scope->isCancelled() ? "true" : "false") . "\n";

// Cancel parent scope - should cascade to children
echo "cancelling parent scope\n";
$parent_scope->cancel(new \Async\CancellationException("Parent cancelled"));

// Check states after parent cancellation
echo "after parent cancel - parent scope cancelled: " . ($parent_scope->isCancelled() ? "true" : "false") . "\n";
echo "after parent cancel - child1 scope cancelled: " . ($child1_scope->isCancelled() ? "true" : "false") . "\n";
echo "after parent cancel - child2 scope cancelled: " . ($child2_scope->isCancelled() ? "true" : "false") . "\n";

// Check coroutine states
echo "parent coroutine cancelled: " . ($parent_coroutine->isCancelled() ? "true" : "false") . "\n";
echo "child1 coroutine cancelled: " . ($child1_coroutine->isCancelled() ? "true" : "false") . "\n";
echo "child2 coroutine cancelled: " . ($child2_coroutine->isCancelled() ? "true" : "false") . "\n";

// Try to get results (should all throw CancellationException)
try {
    $result = $parent_coroutine->getResult();
    echo "parent should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "parent cancelled: " . $e->getMessage() . "\n";
}

try {
    $result = $child1_coroutine->getResult();
    echo "child1 should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "child1 cancelled: " . $e->getMessage() . "\n";
}

try {
    $result = $child2_coroutine->getResult();
    echo "child2 should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "child2 cancelled: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
scopes created
coroutines spawned
parent coroutine started
child1 coroutine started
child2 coroutine started
parent scope cancelled: false
child1 scope cancelled: false
child2 scope cancelled: false
cancelling parent scope
after parent cancel - parent scope cancelled: true
after parent cancel - child1 scope cancelled: true
after parent cancel - child2 scope cancelled: true
parent coroutine cancelled: true
child1 coroutine cancelled: true
child2 coroutine cancelled: true
parent cancelled: Parent cancelled
child1 cancelled: Parent cancelled
child2 cancelled: Parent cancelled
end