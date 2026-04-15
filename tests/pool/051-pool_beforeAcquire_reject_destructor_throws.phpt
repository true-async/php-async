--TEST--
Pool: beforeAcquire rejection + destructor throwing aborts the acquire loop
--FILE--
<?php

use Async\Pool;
use function Async\spawn;

// Covers pool.c:815-821 — zend_async_pool_acquire() branch where
// beforeAcquire rejects a resource AND the destructor throws while
// disposing it, making EG(exception) truthy and forcing the acquire
// to bail out of the retry loop.

$nextId = 0;

$pool = new Pool(
    factory: function () use (&$nextId) {
        return ++$nextId;
    },
    destructor: function ($r) {
        throw new \RuntimeException("boom destroying #$r");
    },
    beforeAcquire: fn($r) => false,
    min: 1,
    max: 4,
);

spawn(function () use ($pool) {
    try {
        $pool->acquire();
        echo "no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
});

echo "end\n";

?>
--EXPECTF--
end
caught: RuntimeException: boom destroying #%d
