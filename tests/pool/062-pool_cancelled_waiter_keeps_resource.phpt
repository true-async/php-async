--TEST--
Pool: a cancelled waiter with no successor leaves its resource reusable
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$created = 0;

$pool = new Pool(
    factory: function() use (&$created) {
        $created++;
        return "r" . $created;
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

suspend();          // holder acquires, waiter parks
suspend();
suspend();          // holder releases: the resource is reserved for the waiter

$waiter->cancel();  // nobody is left to hand the reservation to

try {
    await($waiter);
} catch (Throwable $e) {
}

await($holder);

echo "Factory calls: $created\n";
echo "Idle: " . $pool->idleCount() . "\n";

$late = spawn(function() use ($pool) {
    echo "Late acquired: " . $pool->acquire() . "\n";
});
await($late);

echo "Factory calls: $created\n";
echo "Done\n";
?>
--EXPECT--
Waiter: Async\AsyncCancellation
Factory calls: 1
Idle: 1
Late acquired: r1
Factory calls: 1
Done
