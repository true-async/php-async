--TEST--
Pool: circuit breaker - tryAcquire returns null when INACTIVE
--FILE--
<?php

use Async\Pool;

$pool = new Pool(
    factory: fn() => 42,
    min: 2
);

// First tryAcquire should work
$resource = $pool->tryAcquire();
echo "Before deactivate: " . ($resource !== null ? "got resource" : "null") . "\n";
$pool->release($resource);

// Deactivate circuit breaker
$pool->deactivate();

// Now tryAcquire should return null
$resource = $pool->tryAcquire();
echo "After deactivate: " . ($resource !== null ? "got resource" : "null") . "\n";

// Activate and try again
$pool->activate();
$resource = $pool->tryAcquire();
echo "After activate: " . ($resource !== null ? "got resource" : "null") . "\n";
$pool->release($resource);

echo "Done\n";
?>
--EXPECT--
Before deactivate: got resource
After deactivate: null
After activate: got resource
Done
