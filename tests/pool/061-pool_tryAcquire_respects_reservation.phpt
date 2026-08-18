--TEST--
Pool: tryAcquire does not take a resource reserved for a parked waiter
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

$waiter = spawn(function() use ($pool) {
    $r = $pool->acquire();
    echo "Waiter acquired\n";
    $pool->release($r);
});

suspend();      // holder acquires, waiter parks
suspend();
suspend();      // holder releases: the resource is reserved for the waiter

var_dump($pool->tryAcquire());

await($waiter);
await($holder);

echo "Idle: " . $pool->idleCount() . "\n";
echo "Done\n";
?>
--EXPECT--
NULL
Waiter acquired
Idle: 1
Done
