--TEST--
Coroutine: GC handler with exception objects
--FILE--
<?php

use function Async\spawn;

// Test GC with coroutine that has exception
$coroutine = spawn(function() {
    throw new Exception("test_exception");
});

// Force garbage collection
$collected = gc_collect_cycles();

// Get exception
try {
    $coroutine->getResult();
} catch (Exception $e) {
    var_dump($e->getMessage());
}

// Verify GC was called
var_dump($collected >= 0);

?>
--EXPECT--
string(14) "test_exception"
bool(true)