--TEST--
Pool: release wakes one waiting coroutine
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

$acquired = [];

// Holder
$holder = spawn(function() use ($pool, &$acquired) {
    $r = $pool->acquire();
    $acquired[] = "holder:$r";

    // Let waiter start
    \Async\suspend();
    \Async\suspend();

    $pool->release($r);
    return "holder-done";
});

// Waiter - will block until holder releases
$waiter = spawn(function() use ($pool, &$acquired) {
    $r = $pool->acquire();
    $acquired[] = "waiter:$r";
    $pool->release($r);
    return "waiter-done";
});

$r1 = await($holder);
$r2 = await($waiter);

echo "Holder: $r1\n";
echo "Waiter: $r2\n";
echo "Both acquired resource 1: " . (in_array("holder:1", $acquired) && in_array("waiter:1", $acquired) ? "yes" : "no") . "\n";
echo "Total resources created: " . $pool->count() . "\n";
echo "Done\n";
?>
--EXPECT--
Holder: holder-done
Waiter: waiter-done
Both acquired resource 1: yes
Total resources created: 1
Done
