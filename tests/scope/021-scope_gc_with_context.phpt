--TEST--
Scope: GC handler with context data
--FILE--
<?php

use Async\Scope;
use Async\Context;
use function Async\spawn;

// Test GC with scope context containing ZVALs
$context = new Context();
$context->set("scope_key", "scope_context_value");

// Test object key as well
$obj_key = new stdClass();
$context->set($obj_key, "scope_object_value");

$scope = new Scope($context);

$coroutine = $scope->spawn(function() use ($context, $obj_key) {
    // Access context to ensure it's tracked by GC
    $string_val = $context->get("scope_key");
    $obj_val = $context->get($obj_key);
    return [$string_val, $obj_val];
});

// Force garbage collection to test scope context ZVAL tracking
$collected = gc_collect_cycles();

// Get result
$result = $coroutine->getResult();
var_dump($result);

// Verify GC was called
var_dump($collected >= 0);

?>
--EXPECT--
array(2) {
  [0]=>
  string(19) "scope_context_value"
  [1]=>
  string(18) "scope_object_value"
}
bool(true)