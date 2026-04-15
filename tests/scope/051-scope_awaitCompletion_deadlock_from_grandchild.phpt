--TEST--
Scope: awaitCompletion() called on grandparent from a grandchild-scope coroutine throws
--FILE--
<?php

use Async\Scope;
use function Async\suspend;
use function Async\timeout;

// Covers scope.c:783-796 — async_scope_contains_coroutine() recursive branch
// that walks down into nested child scopes and returns true for a grandchild match.

echo "start\n";

$grand = new Scope();
$middle = Scope::inherit($grand);
$leaf = Scope::inherit($middle);

$leaf->spawn(function () use ($grand) {
    try {
        $grand->awaitCompletion(timeout(1000));
        echo "no error\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});

suspend();

echo "end\n";

?>
--EXPECTF--
start
caught: Cannot await completion of scope from a coroutine that belongs to the same scope or its children
end
