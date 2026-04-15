--TEST--
Scope: gc_get handler walks scope context values and object keys
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\suspend;
use function Async\current_context;

// Covers scope.c:1345-1363 — scope_object_gc() traversal of context->values and context->keys.

echo "start\n";

$scope = new Scope();

$scope->spawn(function () {
    $ctx = current_context();
    $ctx->set("string_key", "value1");
    $ctx->set("number_key", 42);

    // object keys
    $objKey = new stdClass();
    $ctx->set($objKey, "obj_value");

    suspend();
});

suspend();

gc_collect_cycles();

echo "end\n";

?>
--EXPECT--
start
end
