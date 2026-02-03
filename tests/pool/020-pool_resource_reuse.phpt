--TEST--
Pool: resource reuse - released resources are reused
--FILE--
<?php

use Async\Pool;

$created = 0;
$pool = new Pool(
    factory: function() use (&$created) {
        $created++;
        return "resource_$created";
    },
    max: 2
);

// First acquire creates resource
$r1 = $pool->tryAcquire();
echo "1st acquire: $r1 (created: $created)\n";

// Release it
$pool->release($r1);

// Second acquire should reuse
$r2 = $pool->tryAcquire();
echo "2nd acquire: $r2 (created: $created)\n";

// Release and acquire again
$pool->release($r2);
$r3 = $pool->tryAcquire();
echo "3rd acquire: $r3 (created: $created)\n";

echo "Total resources created: $created\n";

echo "Done\n";
?>
--EXPECT--
1st acquire: resource_1 (created: 1)
2nd acquire: resource_1 (created: 1)
3rd acquire: resource_1 (created: 1)
Total resources created: 1
Done
