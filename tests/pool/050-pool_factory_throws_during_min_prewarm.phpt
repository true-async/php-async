--TEST--
Pool: factory throwing during min-size prewarm stops the pre-creation loop
--FILE--
<?php

use Async\Pool;
use Async\PoolException;

// Covers pool.c:775-783 — zend_async_pool_ensure_min() loop break-on-error path
// when the factory throws partway through pre-population.

$n = 0;

try {
    $pool = new Pool(
        factory: function () use (&$n) {
            $n++;
            if ($n >= 2) {
                throw new \RuntimeException("no more");
            }
            return $n;
        },
        min: 3,
        max: 5,
    );
    echo "constructed\n";
    var_dump($n);
    $pool->close();
} catch (\Throwable $e) {
    echo "caught: " . get_class($e) . ": " . $e->getMessage() . "\n";
    var_dump($n);
}

echo "end\n";

?>
--EXPECT--
caught: RuntimeException: no more
int(2)
end
