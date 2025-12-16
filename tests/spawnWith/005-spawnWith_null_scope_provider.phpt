--TEST--
Async\spawnWith: ScopeProvider returning null scope
--FILE--
<?php

use function Async\spawn_with;
use function Async\await;
use Async\ScopeProvider;
use Async\Scope;

class NullScopeProvider implements ScopeProvider
{
    public function provideScope(): ?Scope
    {
        echo "returning null scope\n";
        return null;
    }
}

echo "start\n";

$provider = new NullScopeProvider();

$coroutine = spawn_with($provider, function() {
    echo "coroutine executed\n";
    return "null scope result";
});

$result = await($coroutine);
echo "result: $result\n";

echo "end\n";

?>
--EXPECT--
start
returning null scope
coroutine executed
result: null scope result
end