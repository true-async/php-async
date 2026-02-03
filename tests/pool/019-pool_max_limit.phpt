--TEST--
Pool: max limit - cannot exceed maximum resources
--FILE--
<?php

use Async\Pool;

$created = 0;
$pool = new Pool(
    factory: function() use (&$created) {
        $created++;
        echo "Created: $created\n";
        return $created;
    },
    max: 3
);

// Acquire all 3
$r1 = $pool->tryAcquire();
$r2 = $pool->tryAcquire();
$r3 = $pool->tryAcquire();

echo "Acquired: $r1, $r2, $r3\n";
echo "Total created: $created\n";

// Fourth should fail
$r4 = $pool->tryAcquire();
echo "Fourth: " . var_export($r4, true) . "\n";
echo "Total created after fourth attempt: $created\n";

echo "Done\n";
?>
--EXPECT--
Created: 1
Created: 2
Created: 3
Acquired: 1, 2, 3
Total created: 3
Fourth: NULL
Total created after fourth attempt: 3
Done
