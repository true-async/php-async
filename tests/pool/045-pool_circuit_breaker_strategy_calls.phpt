--TEST--
Pool: circuit breaker - strategy methods are called on release
--FILE--
<?php

use Async\Pool;
use Async\CircuitBreakerStrategy;
use function Async\spawn;

class TestStrategy implements CircuitBreakerStrategy
{
    public int $successCount = 0;
    public int $failureCount = 0;

    public function reportSuccess(mixed $source): void
    {
        $this->successCount++;
        echo "reportSuccess called (source: " . ($source instanceof Pool ? "Pool" : gettype($source)) . ")\n";
    }

    public function reportFailure(mixed $source, \Throwable $error): void
    {
        $this->failureCount++;
        echo "reportFailure called\n";
    }

    public function shouldRecover(): bool
    {
        return true;
    }
}

$strategy = new TestStrategy();

$pool = new Pool(
    factory: fn() => 42,
    min: 1
);

$pool->setCircuitBreakerStrategy($strategy);

spawn(function() use ($pool, $strategy) {
    // Acquire and release - should call reportSuccess
    $resource = $pool->acquire();
    $pool->release($resource);

    echo "Success count: " . $strategy->successCount . "\n";
    echo "Failure count: " . $strategy->failureCount . "\n";
});

echo "Done\n";
?>
--EXPECT--
Done
reportSuccess called (source: Pool)
Success count: 1
Failure count: 0
