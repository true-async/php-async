--TEST--
Pool: closing while a resource is reserved for a waiter destroys it exactly once
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$created = 0;
$destroyed = 0;

$pool = new Pool(
    factory: function() use (&$created) {
        $created++;
        return "r" . $created;
    },
    destructor: function($resource) use (&$destroyed) {
        $destroyed++;
    },
    max: 1
);

$holder = spawn(function() use ($pool) {
    $r = $pool->acquire();
    suspend();
    suspend();
    $pool->release($r);
});

$waiter = spawn(function() use ($pool) {
    try {
        $r = $pool->acquire();
        echo "Waiter acquired\n";
        $pool->release($r);
    } catch (Throwable $e) {
        echo "Waiter: " . get_class($e) . "\n";
    }
});

suspend();      // holder acquires, waiter parks
suspend();
suspend();      // holder releases: the resource is reserved for the waiter

$pool->close();

await($waiter);
await($holder);

echo "Factory calls: $created\n";
echo "Destructor calls: $destroyed\n";
echo "Idle: " . $pool->idleCount() . "\n";
echo "Done\n";
?>
--EXPECT--
Waiter: Async\PoolException
Factory calls: 1
Destructor calls: 1
Idle: 0
Done
