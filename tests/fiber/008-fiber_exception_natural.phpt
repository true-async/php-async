--TEST--
UC-008: Natural exception inside Fiber
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Natural exception in Fiber\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Before exception\n";
        throw new Exception("Fiber error");
    });

    try {
        $fiber->start();
        echo "Should not reach here\n";
    } catch (Exception $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    return "done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Natural exception in Fiber
Before exception
Caught: Fiber error
Test completed
