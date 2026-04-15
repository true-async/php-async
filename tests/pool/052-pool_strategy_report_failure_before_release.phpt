--TEST--
Pool: strategy reportFailure invoked when beforeRelease rejects (no caller-provided error)
--FILE--
<?php

use Async\Pool;
use Async\CircuitBreakerStrategy;
use function Async\spawn;

class RecordingStrategy implements CircuitBreakerStrategy
{
    public int $successCount = 0;
    public int $failureCount = 0;
    public ?string $lastErrorMessage = null;
    public ?string $lastErrorClass = null;

    public function reportSuccess(mixed $source): void
    {
        $this->successCount++;
    }

    public function reportFailure(mixed $source, \Throwable $error): void
    {
        $this->failureCount++;
        $this->lastErrorMessage = $error->getMessage();
        $this->lastErrorClass = get_class($error);
    }

    public function shouldRecover(): bool
    {
        return true;
    }
}

$strategy = new RecordingStrategy();

$pool = new Pool(
    factory: fn() => 42,
    beforeRelease: fn($resource) => false,
);

$pool->setCircuitBreakerStrategy($strategy);

spawn(function() use ($pool, $strategy) {
    $resource = $pool->acquire();
    $pool->release($resource);

    echo "failureCount: {$strategy->failureCount}\n";
    echo "errorClass: {$strategy->lastErrorClass}\n";
    echo "errorMessage: {$strategy->lastErrorMessage}\n";
});

echo "Done\n";
?>
--EXPECT--
Done
failureCount: 1
errorClass: Exception
errorMessage: Resource validation failed
