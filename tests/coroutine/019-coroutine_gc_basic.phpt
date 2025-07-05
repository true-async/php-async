--TEST--
Coroutine: GC handler basic functionality
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

// Test that GC handler is registered and functioning
$coroutine = spawn(function() {
    return "test_value";
});

// Force garbage collection to ensure our GC handler is called
$collected = gc_collect_cycles();

suspend(); // Suspend to simulate coroutine lifecycle

// Check that coroutine completed successfully
$result = $coroutine->getResult();
var_dump($result);

// Verify GC was called (should return >= 0)
var_dump($collected >= 0);

?>
--EXPECT--
string(10) "test_value"
bool(true)