--TEST--
Pool: a coroutine cancelled inside beforeRelease is cancelled, and the backend is not blamed
--FILE--
<?php

use Async\Pool;
use Async\CircuitBreakerStrategy;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$pool = new Pool(
    factory: fn() => new stdClass(),
    destructor: function ($r) { echo "destroyed\n"; },
    beforeRelease: function ($r) {
        delay(1000);
        return true;
    },
    max: 1,
);

$pool->setCircuitBreakerStrategy(new class implements CircuitBreakerStrategy {
    public function reportSuccess(mixed $source): void { echo "success\n"; }
    public function reportFailure(mixed $source, \Throwable $error): void { echo "failure\n"; }
    public function shouldRecover(): bool { return false; }
});

$coroutine = spawn(function () use ($pool) {
    $r = $pool->acquire();
    $pool->release($r);
    echo "not reached\n";
});

spawn(function () use ($coroutine) {
    delay(10);
    $coroutine->cancel();
});

try {
    await($coroutine);
} catch (Async\AsyncCancellation $e) {
    echo get_class($e), "\n";
}

echo "count={$pool->count()} active={$pool->activeCount()}\n";
?>
--EXPECT--
destroyed
Async\AsyncCancellation
count=0 active=0
