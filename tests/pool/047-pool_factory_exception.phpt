--TEST--
Pool: factory exception does not corrupt pool state
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;

$callCount = 0;

$pool = new Pool(
    factory: function() use (&$callCount) {
        $callCount++;
        if ($callCount <= 2) {
            throw new RuntimeException("factory failed (call $callCount)");
        }
        return new stdClass();
    },
    max: 2,
);

// 1. Factory throws — acquire must propagate exception, not hang
$coro1 = spawn(function() use ($pool) {
    try {
        $pool->acquire();
        echo "ERROR: should have thrown\n";
    } catch (RuntimeException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
});
await($coro1);

// 2. Second attempt — also throws
$coro2 = spawn(function() use ($pool) {
    try {
        $pool->acquire();
        echo "ERROR: should have thrown\n";
    } catch (RuntimeException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
});
await($coro2);

// 3. Third attempt — factory succeeds, pool is not corrupted
$coro3 = spawn(function() use ($pool) {
    $resource = $pool->acquire();
    echo "Acquired: " . get_class($resource) . "\n";
    $pool->release($resource);
});
await($coro3);

echo "Pool count: " . $pool->count() . "\n";
echo "Done\n";

?>
--EXPECT--
Caught: factory failed (call 1)
Caught: factory failed (call 2)
Acquired: stdClass
Pool count: 1
Done
