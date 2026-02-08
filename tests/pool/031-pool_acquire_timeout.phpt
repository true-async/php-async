--TEST--
Pool: acquire with timeout throws TimeoutException
--FILE--
<?php

use Async\Pool;
use Async\TimeoutException;
use function Async\spawn;
use function Async\await;

$pool = new Pool(
    factory: function() {
        return "resource";
    },
    max: 1
);

// Hold the only resource
$r = $pool->tryAcquire();
echo "Holding resource\n";

$coroutine = spawn(function() use ($pool) {
    try {
        echo "Trying acquire with 50ms timeout\n";
        $pool->acquire(50);
        echo "ERROR: should not reach here\n";
    } catch (TimeoutException $e) {
        echo "Caught TimeoutException\n";
    }
});

await($coroutine);

echo "Done\n";
?>
--EXPECT--
Holding resource
Trying acquire with 50ms timeout
Caught TimeoutException
Done
