--TEST--
Pool: construct - basic instantiation
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: fn() => 42
);

echo "Pool created\n";
echo "Count: " . $pool->count() . "\n";
echo "Idle: " . $pool->idleCount() . "\n";
echo "Active: " . $pool->activeCount() . "\n";
echo "Is closed: " . ($pool->isClosed() ? "yes" : "no") . "\n";

echo "Done\n";
?>
--EXPECT--
Pool created
Count: 0
Idle: 0
Active: 0
Is closed: no
Done
