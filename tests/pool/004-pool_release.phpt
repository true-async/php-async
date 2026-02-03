--TEST--
Pool: release - return resource to pool
--FILE--
<?php

use Async\Pool;

$counter = 0;
$pool = new Pool(
    factory: function() use (&$counter) {
        return ++$counter;
    },
    max: 2
);

$r1 = $pool->tryAcquire();
echo "Acquired: $r1\n";
echo "Idle: " . $pool->idleCount() . ", Active: " . $pool->activeCount() . "\n";

$pool->release($r1);
echo "Released\n";
echo "Idle: " . $pool->idleCount() . ", Active: " . $pool->activeCount() . "\n";

// Acquire again - should get the same resource
$r2 = $pool->tryAcquire();
echo "Acquired again: $r2\n";
echo "Same resource: " . ($r1 === $r2 ? "yes" : "no") . "\n";

echo "Done\n";
?>
--EXPECT--
Acquired: 1
Idle: 0, Active: 1
Released
Idle: 1, Active: 0
Acquired again: 1
Same resource: yes
Done
