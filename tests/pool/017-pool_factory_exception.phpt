--TEST--
Pool: factory exception - propagates to caller
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: function() {
        throw new RuntimeException("Factory failed");
    }
);

try {
    $pool->tryAcquire();
    echo "ERROR: should have thrown\n";
} catch (RuntimeException $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
Caught: Factory failed
Done
