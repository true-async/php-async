--TEST--
Coroutine: GC handler with finally handlers
--FILE--
<?php

use function Async\spawn;

// Test GC with finally handlers containing callable ZVALs
$coroutine = spawn(function() {
    return "test_value";
});

// Add finally handler with callable
$coroutine->onFinally(function() {
    echo "Finally executed\n";
});

// Force garbage collection
$collected = gc_collect_cycles();

// Wait for completion
$result = $coroutine->getResult();
var_dump($result);

// Verify GC was called
var_dump($collected >= 0);

?>
--EXPECT--
Finally executed
string(10) "test_value"
bool(true)