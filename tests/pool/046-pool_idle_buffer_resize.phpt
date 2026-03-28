--TEST--
Pool: idle buffer grows dynamically beyond initial capacity
--EXTENSIONS--
true_async
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await_all_or_fail;

$counter = 0;

$pool = new Async\Pool(
    factory: function () use (&$counter) {
        return ++$counter;
    },
    max: 15,
);

$acquired = [];
$state = new Async\FutureState();
$barrier = new Async\Future($state);

// Spawn 15 coroutines that all hold a resource simultaneously
$coroutines = [];
for ($i = 0; $i < 15; $i++) {
    $coroutines[] = spawn(function () use ($pool, &$acquired, $barrier) {
        $resource = $pool->acquire();
        $acquired[] = $resource;

        // Wait until all 15 have acquired
        Async\await($barrier);

        $pool->release($resource);
    });
}

// Wait until all 15 have acquired
while (count($acquired) < 15) {
    suspend();
}

echo "All acquired: " . count($acquired) . "\n";
echo "Active count: " . $pool->activeCount() . "\n";

// Release them all
$state->complete(true);
await_all_or_fail($coroutines);

echo "Pool count: " . $pool->count() . "\n";
echo "Idle count: " . $pool->idleCount() . "\n";
echo "Active count after: " . $pool->activeCount() . "\n";
echo "All idle: " . ($pool->idleCount() === 15 ? "yes" : "no") . "\n";

// Acquire one more — should reuse from idle
$coro = spawn(function () use ($pool) {
    $resource = $pool->acquire();
    echo "Reused resource: " . ($resource <= 15 ? "yes" : "no") . "\n";
    $pool->release($resource);
});

await_all_or_fail([$coro]);
echo "Done\n";
?>
--EXPECT--
All acquired: 15
Active count: 15
Pool count: 15
Idle count: 15
Active count after: 0
All idle: yes
Reused resource: yes
Done
