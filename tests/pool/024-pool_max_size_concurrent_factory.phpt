--TEST--
Pool: max_size enforced when factory yields during creation
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

/*
 * Bug: pool_create_resource() (factory) can yield (e.g. TCP connect).
 * active_count was incremented AFTER factory returned, so concurrent
 * coroutines all passed the `total < max_size` check and created
 * connections beyond the limit.
 *
 * Fix: increment active_count BEFORE factory, decrement on failure.
 */

$created = 0;
$pool = new Async\Pool(
    function() use (&$created) {
        $created++;
        delay(50);  // simulate slow factory (TCP connect)
        return "conn-$created";
    },
    null, null, null, null,
    0,  // min
    3,  // max
);

$coros = [];
for ($i = 0; $i < 10; $i++) {
    $coros[] = spawn(function() use ($pool, $i) {
        $conn = $pool->acquire();
        delay(100);  // hold connection
        $pool->release($conn);
    });
}

// Check mid-flight
spawn(function() use ($pool) {
    delay(75);
    echo "During: pool=" . $pool->count() . "\n";
});

foreach ($coros as $c) await($c);

echo "Created: $created\n";
echo "After: pool=" . $pool->count() . "\n";
echo "Done\n";
?>
--EXPECT--
During: pool=3
Created: 3
After: pool=3
Done
