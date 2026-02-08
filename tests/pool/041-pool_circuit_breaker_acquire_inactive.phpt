--TEST--
Pool: circuit breaker - acquire throws when INACTIVE
--FILE--
<?php

use Async\Pool;
use Async\ServiceUnavailableException;
use function Async\spawn;

$pool = new Pool(
    factory: fn() => 42,
    min: 2
);

// Deactivate circuit breaker
$pool->deactivate();

spawn(function() use ($pool) {
    try {
        $pool->acquire();
        echo "ERROR: should have thrown\n";
    } catch (ServiceUnavailableException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
});

echo "Done\n";
?>
--EXPECT--
Done
Caught: Service is unavailable (circuit breaker is open)
