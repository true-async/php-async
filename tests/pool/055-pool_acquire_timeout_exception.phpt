--TEST--
Pool: acquire() reports a timeout as TimeoutException, a dead pool as PoolException
--FILE--
<?php

use Async\Pool;

// A timeout is not a pool failure -- the pool is healthy, just busy -- so it is a TimeoutException,
// like every other deadline in the extension. PoolException means the pool itself is unusable.
$pool = new Pool(factory: fn() => new stdClass(), max: 1);
$held = $pool->acquire();

try {
    $pool->acquire(timeout: 50);
    echo "timeout: NOT THROWN\n";
} catch (Throwable $exception) {
    echo "timeout: ", $exception::class, "\n";
    echo "  is a PoolException: ", var_export($exception instanceof Async\PoolException, true), "\n";
}

$pool->close();

try {
    $pool->acquire();
    echo "closed: NOT THROWN\n";
} catch (Throwable $exception) {
    echo "closed: ", $exception::class, "\n";
}
?>
--EXPECT--
timeout: Async\TimeoutException
  is a PoolException: false
closed: Async\PoolException
