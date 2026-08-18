--TEST--
Pool: resources beyond the ones reserved for waiters stay available
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\await_all;
use function Async\suspend;

$created = 0;

$pool = new Pool(
    factory: function() use (&$created) {
        $created++;
        return "r" . $created;
    },
    max: 3
);

$holders = [];
for ($i = 0; $i < 3; $i++) {
    $holders[] = spawn(function() use ($pool) {
        $r = $pool->acquire();
        suspend();
        suspend();
        $pool->release($r);
    });
}

suspend();

// One waiter: after the three releases one resource is reserved for it, the
// other two are unreserved and must stay acquirable.
$waiter = spawn(function() use ($pool) {
    $r = $pool->acquire();
    echo "Waiter acquired\n";
    suspend();
    suspend();
    suspend();
    $pool->release($r);
});

suspend();      // waiter parks
suspend();      // three releases in one tick, one reservation made

echo "Idle: " . $pool->idleCount() . "\n";

$surplus = $pool->tryAcquire();
var_dump($surplus !== null);
$pool->release($surplus);

await($waiter);
await_all($holders);

echo "Created: $created\n";
echo "Done\n";
?>
--EXPECT--
Idle: 3
bool(true)
Waiter acquired
Created: 3
Done
