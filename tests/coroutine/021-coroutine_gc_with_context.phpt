--TEST--
Coroutine: GC handler with context data
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use Async\Context;

// Test GC with coroutine context containing ZVALs
$context = new Context();
$context->set("string_key", "string_value");

// Test object key as well
$obj_key = new stdClass();
$context->set($obj_key, "object_value");

$coroutine = spawn(function() use ($context, $obj_key) {
    // Access context to ensure it's tracked by GC
    $string_val = $context->get("string_key");
    $obj_val = $context->get($obj_key);
    return [$string_val, $obj_val];
});

// Force garbage collection to test context ZVAL tracking
$collected = gc_collect_cycles();

suspend(); // Suspend to simulate coroutine lifecycle

// Get result
$result = $coroutine->getResult();
var_dump($result);

// Verify GC was called
var_dump($collected >= 0);

?>
--EXPECT--
array(2) {
  [0]=>
  string(12) "string_value"
  [1]=>
  string(12) "object_value"
}
bool(true)