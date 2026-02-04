--TEST--
Pool: circuit breaker - state transitions
--FILE--
<?php

use Async\Pool;
use Async\CircuitBreakerState;

$pool = new Pool(
    factory: fn() => 42
);

// Initial state should be ACTIVE
$state = $pool->getState();
echo "Initial: " . $state->name . "\n";

// Transition to INACTIVE
$pool->deactivate();
$state = $pool->getState();
echo "After deactivate: " . $state->name . "\n";

// Transition to RECOVERING
$pool->recover();
$state = $pool->getState();
echo "After recover: " . $state->name . "\n";

// Transition back to ACTIVE
$pool->activate();
$state = $pool->getState();
echo "After activate: " . $state->name . "\n";

echo "Done\n";
?>
--EXPECT--
Initial: ACTIVE
After deactivate: INACTIVE
After recover: RECOVERING
After activate: ACTIVE
Done
