--TEST--
Pool: construct - pre-warming with min parameter
--FILE--
<?php

use Async\Pool;

$counter = 0;
$pool = new Pool(
    factory: function() use (&$counter) {
        $counter++;
        echo "Factory called: $counter\n";
        return $counter;
    },
    min: 3,
    max: 5
);

echo "Pool created\n";
echo "Count: " . $pool->count() . "\n";
echo "Idle: " . $pool->idleCount() . "\n";
echo "Active: " . $pool->activeCount() . "\n";

echo "Done\n";
?>
--EXPECT--
Factory called: 1
Factory called: 2
Factory called: 3
Pool created
Count: 3
Idle: 3
Active: 0
Done
