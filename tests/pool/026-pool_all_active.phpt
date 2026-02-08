--TEST--
Pool: all active - tryAcquire returns null when all resources in use
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return ++$c;
    },
    min: 2,
    max: 2
);

echo "Initial: idle=" . $pool->idleCount() . ", active=" . $pool->activeCount() . "\n";

$r1 = $pool->tryAcquire();
echo "After 1st: idle=" . $pool->idleCount() . ", active=" . $pool->activeCount() . "\n";

$r2 = $pool->tryAcquire();
echo "After 2nd: idle=" . $pool->idleCount() . ", active=" . $pool->activeCount() . "\n";

// All resources are active now
$r3 = $pool->tryAcquire();
echo "3rd result: " . var_export($r3, true) . "\n";

// Release one
$pool->release($r1);
echo "After release: idle=" . $pool->idleCount() . ", active=" . $pool->activeCount() . "\n";

// Now should work
$r4 = $pool->tryAcquire();
echo "4th result: $r4\n";

echo "Done\n";
?>
--EXPECT--
Initial: idle=2, active=0
After 1st: idle=1, active=1
After 2nd: idle=0, active=2
3rd result: NULL
After release: idle=1, active=1
4th result: 1
Done
