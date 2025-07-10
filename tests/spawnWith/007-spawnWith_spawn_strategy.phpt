--TEST--
Async\spawnWith: SpawnStrategy with hooks
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;
use Async\SpawnStrategy;
use Async\Scope;
use Async\Coroutine;

class TestSpawnStrategy implements SpawnStrategy
{
    private $scope;
    
    public function __construct()
    {
        $this->scope = new Scope();
    }
    
    public function provideScope(): ?Scope
    {
        echo "provideScope called\n";
        return $this->scope;
    }
    
    public function beforeCoroutineEnqueue(Coroutine $coroutine, Scope $scope): array
    {
        echo "beforeCoroutineEnqueue called with coroutine ID: " . $coroutine->getId() . "\n";
        return [];
    }
    
    public function afterCoroutineEnqueue(Coroutine $coroutine, Scope $scope): void
    {
        echo "afterCoroutineEnqueue called with coroutine ID: " . $coroutine->getId() . "\n";
    }
}

echo "start\n";

$strategy = new TestSpawnStrategy();

$coroutine = spawnWith($strategy, function() {
    echo "coroutine executed\n";
    return "strategy result";
});

$result = await($coroutine);
echo "result: $result\n";

echo "end\n";

?>
--EXPECTF--
start
provideScope called
beforeCoroutineEnqueue called with coroutine ID: %d
afterCoroutineEnqueue called with coroutine ID: %d
coroutine executed
result: strategy result
end