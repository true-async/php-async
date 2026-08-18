--TEST--
Context: find() walks past a parent scope that has no context at all
--FILE--
<?php

use Async\Scope;
use function Async\await;
use function Async\current_context;
use function Async\root_context;

// Regression: a scope receives a context only when someone asks for one, so a middle scope
// without a context must not cut the lookup off from its ancestors.

echo "start\n";

$grand = new Scope();
$middle = Scope::inherit($grand);
$leaf = Scope::inherit($middle);

await($grand->spawn(function () {
    current_context()->set('grandkey', 'value-from-grand');
}));

await($leaf->spawn(function () {
    $ctx = current_context();
    var_dump($ctx->find('grandkey'));
    var_dump($ctx->has('grandkey'));
}));

// The shape an HTTP server produces: main scope -> server scope -> per-request scope,
// where neither intermediate scope ever touched its context.
root_context()->set('rootkey', 'value-from-root');

$server = Scope::inherit();

await($server->spawn(function () {
    $request = Scope::inherit();

    await($request->spawn(function () {
        var_dump(current_context()->find('rootkey'));
    }));
}));

echo "end\n";

?>
--EXPECT--
start
string(16) "value-from-grand"
bool(true)
string(15) "value-from-root"
end
