--TEST--
Pool: circuit breaker - RECOVERING state allows acquire
--FILE--
<?php

use Async\Pool;
use function Async\spawn;

$pool = new Pool(
    factory: fn() => 42,
    min: 1
);

// Set to RECOVERING state
$pool->recover();
echo "State: " . $pool->getState()->name . "\n";

spawn(function() use ($pool) {
    // RECOVERING should allow acquire
    $resource = $pool->acquire();
    echo "Acquired in RECOVERING: " . ($resource !== null ? "yes" : "no") . "\n";
    $pool->release($resource);
});

echo "Done\n";
?>
--EXPECT--
State: RECOVERING
Done
Acquired in RECOVERING: yes
