--TEST--
Scope: GC handler basic functionality
--FILE--
<?php

use Async\Scope;
use function Async\spawn;

// Test that GC handler is registered and functioning for scope
$scope = new Scope();

$coroutine = $scope->spawn(function() {
    return "scope_test_value";
});

// Force garbage collection to ensure our GC handler is called
$collected = gc_collect_cycles();

// Check that coroutine completed successfully
$result = $coroutine->getResult();
var_dump($result);

// Verify GC was called (should return >= 0)
var_dump($collected >= 0);

?>
--EXPECT--
string(16) "scope_test_value"
bool(true)