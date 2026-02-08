--TEST--
Pool: tryAcquire on closed pool returns null
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: fn() => 1
);

$pool->close();

$r = $pool->tryAcquire();
echo "Result: " . var_export($r, true) . "\n";

echo "Done\n";
?>
--EXPECT--
Result: NULL
Done
