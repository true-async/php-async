--TEST--
Pool: a scalar resource released more often than acquired is refused
--FILE--
<?php

use Async\Pool;
use Async\PoolException;

$pool = new Pool(factory: fn() => 7, beforeRelease: fn($r) => false, max: 1);

$r = $pool->acquire();
$pool->release($r);

try {
    $pool->release($r);
} catch (PoolException $e) {
    echo $e->getMessage(), "\n";
}

echo "count={$pool->count()} active={$pool->activeCount()}\n";

// The slot is free: acquire does not wait.
var_dump($pool->acquire(timeout: 50));
?>
--EXPECT--
Resource is not acquired from this pool
count=0 active=0
int(7)
