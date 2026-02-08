--TEST--
Pool: multiple coroutines waiting for same resource
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return ++$c;
    },
    max: 1
);

$served = [];

// Holder takes the only resource
$holder = spawn(function() use ($pool) {
    $r = $pool->acquire();

    // Let all waiters queue up
    for ($i = 0; $i < 5; $i++) {
        \Async\suspend();
    }

    $pool->release($r);
});

// Multiple waiters
$waiters = [];
foreach (['A', 'B', 'C'] as $name) {
    $waiters[] = spawn(function() use ($pool, $name, &$served) {
        $r = $pool->acquire();
        $served[$name] = $r;
        $pool->release($r);
    });
}

await($holder);
foreach ($waiters as $w) {
    await($w);
}

echo "Waiters served: " . count($served) . "\n";
echo "All got resource 1: " . (array_unique(array_values($served)) === [1] ? "yes" : "no") . "\n";
echo "Total resources created: " . $pool->count() . "\n";
?>
--EXPECT--
Waiters served: 3
All got resource 1: yes
Total resources created: 1
