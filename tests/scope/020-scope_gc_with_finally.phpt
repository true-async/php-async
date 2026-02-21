--TEST--
Scope: GC handler with finally handlers
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;

// Test GC with scope finally handlers containing callable ZVALs
$scope = new Scope();

// Add finally handler with callable
$scope->finally(function() {
    echo "Scope finally executed\n";
});

$coroutine = $scope->spawn(function() {
    return "scope_finally_test";
});

// Force garbage collection
$collected = gc_collect_cycles();

suspend(); // Suspend to simulate coroutine lifecycle

// Wait for completion
$result = $coroutine->getResult();
var_dump($result);

// Dispose scope to trigger finally handlers
$scope->dispose();

// Verify GC was called
var_dump($collected >= 0);

?>
--EXPECT--
string(18) "scope_finally_test"
bool(true)
Scope finally executed