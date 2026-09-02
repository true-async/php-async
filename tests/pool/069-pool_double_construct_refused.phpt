--TEST--
Pool: a second __construct() is refused instead of orphaning the first pool
--EXTENSIONS--
true_async
--FILE--
<?php

use Async\Pool;

$pool = new Pool(factory: fn() => 'first', min: 2, max: 4);

printf("idle=%d active=%d\n", $pool->idleCount(), $pool->activeCount());

try {
    $pool->__construct(factory: fn() => 'second', min: 1, max: 2);
    echo "returned\n";
} catch (Async\PoolException $e) {
    echo $e->getMessage(), "\n";
}

/* A guard that threw after replacing anything would still pass the check above. */
printf("idle=%d active=%d\n", $pool->idleCount(), $pool->activeCount());
var_dump($pool->tryAcquire());

echo "Done\n";
?>
--EXPECT--
idle=2 active=0
Pool is already initialized
idle=2 active=0
string(5) "first"
Done
