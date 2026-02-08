--TEST--
Pool: close wakes ALL waiting coroutines with exception
--FILE--
<?php

use Async\Pool;
use Async\PoolException;
use function Async\spawn;
use function Async\await;

$pool = new Pool(
    factory: function() {
        return "resource";
    },
    max: 1
);

// Block the resource
$r = $pool->tryAcquire();

$exceptions = [];

// Multiple waiters
$waiters = [];
for ($i = 0; $i < 3; $i++) {
    $id = $i;
    $waiters[] = spawn(function() use ($pool, $id, &$exceptions) {
        try {
            $pool->acquire();
        } catch (PoolException $e) {
            $exceptions[$id] = $e->getMessage();
        }
    });
}

// Let all waiters start waiting
spawn(function() use ($pool) {
    \Async\suspend();
    \Async\suspend();
    \Async\suspend();
    $pool->close();
});

foreach ($waiters as $w) {
    await($w);
}

echo "Exceptions caught: " . count($exceptions) . "\n";
echo "All got 'Pool is closed': " . (
    count(array_filter($exceptions, fn($m) => $m === "Pool is closed")) === 3 ? "yes" : "no"
) . "\n";
echo "Done\n";
?>
--EXPECT--
Exceptions caught: 3
All got 'Pool is closed': yes
Done
