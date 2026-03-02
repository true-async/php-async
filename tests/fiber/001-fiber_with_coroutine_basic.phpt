--TEST--
Fiber with coroutine: Basic fiber creation and execution when async is active
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Fiber creation with active async scheduler\n";

$coroutine = spawn(function() {
    echo "Coroutine started\n";

    // Create a fiber while async scheduler is active
    $fiber = new Fiber(function() {
        echo "Fiber executing\n";
        return "fiber result";
    });

    echo "Starting fiber\n";
    $fiber->start();
    $result = $fiber->getReturn();
    echo "Fiber returned: " . $result . "\n";

    return "coroutine result";
});

$result = await($coroutine);
echo "Coroutine completed with: " . $result . "\n";

echo "Test completed\n";
?>
--EXPECT--
Test: Fiber creation with active async scheduler
Coroutine started
Starting fiber
Fiber executing
Fiber returned: fiber result
Coroutine completed with: coroutine result
Test completed