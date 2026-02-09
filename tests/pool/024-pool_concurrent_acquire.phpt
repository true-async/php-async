--TEST--
Pool: concurrent acquire - multiple coroutines share pool
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        $id = ++$c;
        echo "Created: $id\n";
        return $id;
    },
    max: 2
);

// Spawn 3 coroutines that acquire and release
// Since they run sequentially (no I/O wait), they reuse resources
$c1 = spawn(function() use ($pool) {
    $r = $pool->acquire();
    $pool->release($r);
    return "c1:$r";
});

$c2 = spawn(function() use ($pool) {
    $r = $pool->acquire();
    $pool->release($r);
    return "c2:$r";
});

$c3 = spawn(function() use ($pool) {
    $r = $pool->acquire();
    $pool->release($r);
    return "c3:$r";
});

$r1 = await($c1);
$r2 = await($c2);
$r3 = await($c3);

echo "Results: $r1, $r2, $r3\n";
echo "All coroutines completed\n";

echo "Done\n";
?>
--EXPECT--
Created: 1
Results: c1:1, c2:1, c3:1
All coroutines completed
Done
