--TEST--
Async\spawnWith: custom ScopeProvider implementation
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;
use Async\ScopeProvider;
use Async\Scope;

class CustomScopeProvider implements ScopeProvider
{
    private $scope;
    
    public function __construct()
    {
        $this->scope = new Scope();
        echo "CustomScopeProvider created\n";
    }
    
    public function provideScope(): ?Scope
    {
        echo "provideScope called\n";
        return $this->scope;
    }
}

echo "start\n";

$provider = new CustomScopeProvider();

$coroutine = spawnWith($provider, function() {
    echo "coroutine executed\n";
    return "custom provider result";
});

$result = await($coroutine);
echo "result: $result\n";

echo "end\n";

?>
--EXPECT--
start
CustomScopeProvider created
provideScope called
coroutine executed
result: custom provider result
end