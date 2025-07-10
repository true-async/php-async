--TEST--
Async\spawnWith: SpawnStrategy hook execution order
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;
use Async\SpawnStrategy;
use Async\Scope;
use Async\Coroutine;

class OrderTestStrategy implements SpawnStrategy
{
    private $scope;
    
    public function __construct()
    {
        $this->scope = new Scope();
    }
    
    public function provideScope(): ?Scope
    {
        echo "1. provideScope\n";
        return $this->scope;
    }
    
    public function beforeCoroutineEnqueue(Coroutine $coroutine, Scope $scope): array
    {
        echo "2. beforeCoroutineEnqueue\n";
        return [];
    }
    
    public function afterCoroutineEnqueue(Coroutine $coroutine, Scope $scope): void
    {
        echo "4. afterCoroutineEnqueue\n";
    }
}

echo "start\n";

$strategy = new OrderTestStrategy();

$coroutine = spawnWith($strategy, function() {
    echo "3. coroutine executed\n";
    return "order test";
});

$result = await($coroutine);
echo "result: $result\n";

echo "end\n";

?>
--EXPECT--
start
1. provideScope
2. beforeCoroutineEnqueue
4. afterCoroutineEnqueue
3. coroutine executed
result: order test
end