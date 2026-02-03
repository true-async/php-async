--TEST--
Pool: exception - acquire on closed pool throws
--FILE--
<?php

use Async\Pool;
use Async\PoolException;
use function Async\spawn;

$pool = new Pool(
    factory: fn() => 1
);

$pool->close();

spawn(function() use ($pool) {
    try {
        $pool->acquire();
        echo "ERROR: should have thrown\n";
    } catch (PoolException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
});

echo "Done\n";
?>
--EXPECT--
Done
Caught: Pool is closed
