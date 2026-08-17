--TEST--
Pool: a waiter leaving through the circuit breaker hands its reservation on
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$pool = new Pool(factory: fn() => 'r', max: 1);

$holder = spawn(function() use ($pool) {
    $r = $pool->acquire();
    suspend();
    suspend();
    $pool->release($r);
});

$w1 = spawn(function() use ($pool) {
    try {
        $pool->acquire();
        echo "W1 acquired\n";
    } catch (Throwable $e) {
        echo "W1: " . get_class($e) . "\n";
    }
});

$w2 = spawn(function() use ($pool) {
    try {
        $pool->acquire();
        echo "W2 acquired\n";
    } catch (Throwable $e) {
        echo "W2: " . get_class($e) . "\n";
    }
});

suspend();      // holder acquires, W1 and W2 park
suspend();
suspend();      // holder releases: the resource is reserved for W1

// W1 has not run yet: it finds the pool unavailable and leaves without
// spending its reservation.
$pool->deactivate();

await($w1);
await($w2);
await($holder);

echo "Done\n";
?>
--EXPECT--
W1: Async\ServiceUnavailableException
W2: Async\ServiceUnavailableException
Done
