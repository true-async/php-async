--TEST--
Pool: releasing an object twice is refused and leaves the counts intact
--FILE--
<?php

use Async\Pool;
use Async\PoolException;

$pool = new Pool(factory: fn() => new stdClass(), max: 2);

$a = $pool->acquire();
$b = $pool->acquire();
$pool->release($a);

try {
    $pool->release($a);
} catch (PoolException $e) {
    echo $e->getMessage(), "\n";
}

echo "active={$pool->activeCount()} idle={$pool->idleCount()}\n";

// A foreign object was never acquired from this pool.
try {
    $pool->release(new stdClass());
} catch (PoolException $e) {
    echo $e->getMessage(), "\n";
}

// The idle resource is handed out once: the second acquire gets a new one.
$c = $pool->acquire();
var_dump($c === $a);
$pool->release($b);
$d = $pool->acquire();
var_dump($d === $b, $d === $c);

// Released and acquired again, the same object may be released again.
$pool->release($c);
$pool->release($d);
echo "active={$pool->activeCount()} idle={$pool->idleCount()}\n";
?>
--EXPECT--
Resource is not acquired from this pool
active=1 idle=1
Resource is not acquired from this pool
bool(true)
bool(true)
bool(false)
active=0 idle=2
