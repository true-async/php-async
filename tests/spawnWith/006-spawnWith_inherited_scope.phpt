--TEST--
Async\spawnWith: with inherited scope
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;
use Async\Scope;

echo "start\n";

$parentScope = new Scope();
$childScope = Scope::inherit($parentScope);

$coroutine = spawnWith($childScope, function() {
    echo "coroutine in child scope\n";
    return "inherited scope result";
});

$result = await($coroutine);
echo "result: $result\n";

echo "end\n";

?>
--EXPECT--
start
coroutine in child scope
result: inherited scope result
end