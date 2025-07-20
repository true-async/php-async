--TEST--
Scope inheritance cancellation isolation (child cancellation should not affect parent)
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "start\n";

// Create parent scope
$parent_scope = new \Async\Scope();
$parent_scope->asNotSafely();

// Create multiple child scopes
$child1_scope = \Async\Scope::inherit($parent_scope);
$child2_scope = \Async\Scope::inherit($parent_scope);
$child3_scope = \Async\Scope::inherit($parent_scope);

echo "parent and child scopes created\n";

// Spawn coroutines in all scopes
$parent_coroutine = $parent_scope->spawn(function() {
    echo "parent coroutine started\n";
    suspend();
    suspend();
    echo "parent coroutine completed\n";
    return "parent_result";
});

$child1_coroutine = $child1_scope->spawn(function() {
    echo "child1 coroutine started\n";
    suspend();
    echo "child1 should not complete\n";
    return "child1_result";
});

$child2_coroutine = $child2_scope->spawn(function() {
    echo "child2 coroutine started\n";
    suspend();
    suspend();
    echo "child2 coroutine completed\n";
    return "child2_result";
});

$child3_coroutine = $child3_scope->spawn(function() {
    echo "child3 coroutine started\n";
    suspend();
    suspend();
    suspend();
    echo "child3 should not complete\n";
    return "child3_result";
});

echo "all coroutines spawned\n";

// Let coroutines start
suspend();

// Cancel only child1 - should not affect parent or other children
echo "cancelling child1 scope only\n";
$child1_scope->cancel(new \Async\CancellationError("Child1 cancelled"));

suspend();

// Check isolation - only child1 should be cancelled
echo "after child1 cancellation:\n";
echo "parent scope cancelled: " . ($parent_scope->isCancelled() ? "true" : "false") . "\n";
echo "child1 scope cancelled: " . ($child1_scope->isCancelled() ? "true" : "false") . "\n";
echo "child2 scope cancelled: " . ($child2_scope->isCancelled() ? "true" : "false") . "\n";
echo "child3 scope cancelled: " . ($child3_scope->isCancelled() ? "true" : "false") . "\n";

// Continue execution for non-cancelled scopes
suspend();

// Cancel child3 as well
echo "cancelling child3 scope\n";
$child3_scope->cancel(new \Async\CancellationError("Child3 cancelled"));

// Let cancellation propagate
suspend();

echo "after child3 cancellation:\n";
echo "parent scope cancelled: " . ($parent_scope->isCancelled() ? "true" : "false") . "\n";
echo "child1 scope cancelled: " . ($child1_scope->isCancelled() ? "true" : "false") . "\n";
echo "child2 scope cancelled: " . ($child2_scope->isCancelled() ? "true" : "false") . "\n";
echo "child3 scope cancelled: " . ($child3_scope->isCancelled() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECTF--
start
parent and child scopes created
all coroutines spawned
parent coroutine started
child1 coroutine started
child2 coroutine started
child3 coroutine started
cancelling child1 scope only
after child1 cancellation:
parent scope cancelled: false
child1 scope cancelled: true
child2 scope cancelled: false
child3 scope cancelled: false
parent coroutine completed
child2 coroutine completed
cancelling child3 scope
after child3 cancellation:
parent scope cancelled: false
child1 scope cancelled: true
child2 scope cancelled: false
child3 scope cancelled: true
end