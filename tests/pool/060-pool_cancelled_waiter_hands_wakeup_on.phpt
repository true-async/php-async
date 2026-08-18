--TEST--
Pool: a cancelled waiter hands its reservation to the next one
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
        $r = $pool->acquire();
        echo "W1 acquired\n";
        $pool->release($r);
    } catch (Throwable $e) {
        echo "W1: " . get_class($e) . "\n";
    }
});

$w2 = spawn(function() use ($pool) {
    $r = $pool->acquire();
    echo "W2 acquired\n";
    $pool->release($r);
});

suspend();      // holder acquires, W1 and W2 park in that order
suspend();
suspend();      // holder releases: the resource is reserved for W1

$w1->cancel();  // W1 is cancelled before it can take the reserved resource

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
Idle: 1
Done
