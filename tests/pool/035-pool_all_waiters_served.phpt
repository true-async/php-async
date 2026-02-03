--TEST--
Pool: all waiting coroutines eventually get resources
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

// First, take the resource
$holder = spawn(function() use ($pool) {
    $r = $pool->acquire();
    // Hold briefly then release
    \Async\suspend();
    $pool->release($r);
});

// Multiple waiters
$waiters = [];
for ($i = 0; $i < 3; $i++) {
    $id = chr(65 + $i); // A, B, C
    $waiters[] = spawn(function() use ($pool, $id, &$served) {
        $r = $pool->acquire();
        $served[$id] = $r;
        \Async\suspend(); // Brief hold
        $pool->release($r);
    });
}

await($holder);
foreach ($waiters as $w) {
    await($w);
}

echo "Waiters served: " . count($served) . "\n";
echo "A served: " . (isset($served['A']) ? "yes" : "no") . "\n";
echo "B served: " . (isset($served['B']) ? "yes" : "no") . "\n";
echo "C served: " . (isset($served['C']) ? "yes" : "no") . "\n";
echo "Done\n";
?>
--EXPECT--
Waiters served: 3
A served: yes
B served: yes
C served: yes
Done
