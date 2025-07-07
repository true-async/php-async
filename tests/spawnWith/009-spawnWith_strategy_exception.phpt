--TEST--
Async\spawnWith: SpawnStrategy with exception in coroutine
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;
use Async\SpawnStrategy;
use Async\Scope;
use Async\Coroutine;

class ExceptionTestStrategy implements SpawnStrategy
{
    private $scope;
    
    public function __construct()
    {
        $this->scope = new Scope();
    }
    
    public function provideScope(): ?Scope
    {
        return $this->scope;
    }
    
    public function beforeCoroutineEnqueue(Coroutine $coroutine, Scope $scope): array
    {
        echo "before coroutine enqueue\n";
        return [];
    }
    
    public function afterCoroutineEnqueue(Coroutine $coroutine, Scope $scope): void
    {
        echo "after coroutine enqueue\n";
    }
}

echo "start\n";

$strategy = new ExceptionTestStrategy();

$coroutine = spawnWith($strategy, function() {
    echo "coroutine start\n";
    throw new RuntimeException("test exception from coroutine");
});

try {
    await($coroutine);
} catch (RuntimeException $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
before coroutine enqueue
after coroutine enqueue
coroutine start
caught exception: test exception from coroutine
end