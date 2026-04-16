--TEST--
Context: three-level scope hierarchy walks past intermediate empty contexts
--FILE--
<?php

use Async\Scope;
use function Async\spawn;
use function Async\current_context;
use function Async\await;

// Covers context.c:55-67 — the loop-body iteration of async_context_find()
// that advances past parent scopes which either have no context at all
// or do not contain the requested key.

echo "start\n";

$grand = new Scope();
$middle = Scope::inherit($grand);
$leaf = Scope::inherit($middle);

await($grand->spawn(function () {
    current_context()->set('grandkey', 'value-from-grand');
}));

// Touch middle's context so the lookup can walk past it.
await($middle->spawn(function () {
    current_context()->set('middlekey', 'value-from-middle');
}));

await($leaf->spawn(function () {
    $ctx = current_context();
    var_dump($ctx->find('grandkey'));
    var_dump($ctx->has('grandkey'));
    // Key absent at every level — find() should return null after walking up.
    var_dump($ctx->find('missing'));
    var_dump($ctx->has('missing'));
}));

echo "end\n";

?>
--EXPECT--
start
string(16) "value-from-grand"
bool(true)
NULL
bool(false)
end
