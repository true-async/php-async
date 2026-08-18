--TEST--
Pool: a cancelled waiter hands on a reservation that was capacity, not a resource
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

// beforeRelease rejects every resource, so a release frees capacity and leaves
// the idle buffer empty: the reservation is the right to create, nothing else.
$pool = new Pool(
    factory: fn() => 'r',
    beforeRelease: fn($r) => false,
    max: 1
);

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
    $pool->acquire();
    echo "W2 acquired\n";
});

suspend();      // holder acquires, W1 and W2 park
suspend();
suspend();      // holder releases a rejected resource: capacity is reserved for W1

$w1->cancel();

try {
    await($w1);
} catch (Throwable $e) {
}

await($w2);
await($holder);

echo "Idle: " . $pool->idleCount() . "\n";
echo "Done\n";
?>
--EXPECT--
W1: Async\AsyncCancellation
W2 acquired
Idle: 0
Done
