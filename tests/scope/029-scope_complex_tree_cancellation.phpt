--TEST--
Complex scope tree cancellation with multi-level hierarchy
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "start\n";

// Create complex scope tree: parent -> child -> grandchild -> great-grandchild
$parent_scope = new \Async\Scope()->asNotSafely();
$child_scope = \Async\Scope::inherit($parent_scope);
$grandchild_scope = \Async\Scope::inherit($child_scope);
$great_grandchild_scope = \Async\Scope::inherit($grandchild_scope);

// Create sibling branch: parent -> sibling -> sibling_child
$sibling_scope = \Async\Scope::inherit($parent_scope);
$sibling_child_scope = \Async\Scope::inherit($sibling_scope);

echo "complex scope tree created\n";

// Spawn coroutines in each scope
$scopes_and_coroutines = [
    'parent' => [$parent_scope, null],
    'child' => [$child_scope, null],
    'grandchild' => [$grandchild_scope, null],
    'great_grandchild' => [$great_grandchild_scope, null],
    'sibling' => [$sibling_scope, null],
    'sibling_child' => [$sibling_child_scope, null]
];

foreach ($scopes_and_coroutines as $name => &$data) {
    $scope = $data[0];
    $data[1] = $scope->spawn(function() use ($name) {
        echo "$name coroutine started\n";
        suspend();
        suspend();
        suspend();
        echo "$name coroutine should not complete\n";
        return "{$name}_result";
    });
}

echo "all coroutines spawned\n";

// Let all coroutines start
suspend();

// Verify initial states (all should be false)
foreach ($scopes_and_coroutines as $name => $data) {
    $scope = $data[0];
    echo "$name scope initially cancelled: " . ($scope->isCancelled() ? "true" : "false") . "\n";
}

// Cancel middle node (child_scope) - should cancel its descendants but not ancestors
echo "cancelling child scope (middle node)\n";
$child_scope->cancel(new \Async\CancellationError("Child cancelled"));

// Let cancellation propagate
suspend();

// Check cancellation propagation
echo "after child cancellation:\n";
foreach ($scopes_and_coroutines as $name => $data) {
    $scope = $data[0];
    echo "$name scope cancelled: " . ($scope->isCancelled() ? "true" : "false") . "\n";
}

// Now cancel parent - should cancel everything remaining
echo "cancelling parent scope (root)\n";
$parent_scope->cancel(new \Async\CancellationError("Parent cancelled"));

// Let cancellation propagate
suspend();

echo "after parent cancellation:\n";
foreach ($scopes_and_coroutines as $name => $data) {
    $scope = $data[0];
    echo "$name scope cancelled: " . ($scope->isCancelled() ? "true" : "false") . "\n";
}

// Verify all coroutines are cancelled
echo "coroutine cancellation results:\n";
foreach ($scopes_and_coroutines as $name => $data) {
    $coroutine = $data[1];
    if ($coroutine->isCancelled()) {
        echo "$name coroutine cancelled: true\n";
    }
}

echo "end\n";

?>
--EXPECTF--
start
complex scope tree created
all coroutines spawned
parent coroutine started
child coroutine started
grandchild coroutine started
great_grandchild coroutine started
sibling coroutine started
sibling_child coroutine started
parent scope initially cancelled: false
child scope initially cancelled: false
grandchild scope initially cancelled: false
great_grandchild scope initially cancelled: false
sibling scope initially cancelled: false
sibling_child scope initially cancelled: false
cancelling child scope (middle node)
after child cancellation:
parent scope cancelled: false
child scope cancelled: true
grandchild scope cancelled: true
great_grandchild scope cancelled: true
sibling scope cancelled: false
sibling_child scope cancelled: false
cancelling parent scope (root)
after parent cancellation:
parent scope cancelled: true
child scope cancelled: true
grandchild scope cancelled: true
great_grandchild scope cancelled: true
sibling scope cancelled: true
sibling_child scope cancelled: true
coroutine cancellation results:
parent coroutine cancelled: true
child coroutine cancelled: true
grandchild coroutine cancelled: true
great_grandchild coroutine cancelled: true
sibling coroutine cancelled: true
sibling_child coroutine cancelled: true
end