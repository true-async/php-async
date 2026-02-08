--TEST--
Pool: tryAcquire - non-blocking acquire
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

// First acquire creates resource
$r1 = $pool->tryAcquire();
echo "Got: $r1\n";
echo "Active: " . $pool->activeCount() . "\n";

// Second acquire creates another resource
$r2 = $pool->tryAcquire();
echo "Got: $r2\n";
echo "Active: " . $pool->activeCount() . "\n";

// Third acquire fails (max reached, none idle)
$r3 = $pool->tryAcquire();
echo "Got: " . var_export($r3, true) . "\n";

echo "Done\n";
?>
--EXPECT--
Got: 1
Active: 1
Got: 2
Active: 2
Got: NULL
Done
