--TEST--
Pool: close() wakes waiting coroutines with exception
--FILE--
<?php

use Async\Pool;
use Async\PoolException;
use function Async\spawn;

$pool = new Pool(
    factory: function() {
        return "resource";
    },
    max: 1
);

// Hold the only resource
$r = $pool->tryAcquire();

// Waiter will block
spawn(function() use ($pool) {
    try {
        echo "Waiter: trying to acquire\n";
        $pool->acquire();
        echo "ERROR: should not reach here\n";
    } catch (PoolException $e) {
        echo "Waiter: " . $e->getMessage() . "\n";
    }
});

// Closer runs after waiter blocks
spawn(function() use ($pool) {
    echo "Closer: closing pool\n";
    $pool->close();
    echo "Closer: done\n";
});

echo "Main: done\n";
?>
--EXPECT--
Main: done
Waiter: trying to acquire
Closer: closing pool
Closer: done
Waiter: Pool is closed
