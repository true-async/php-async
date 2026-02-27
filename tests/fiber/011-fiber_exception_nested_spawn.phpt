--TEST--
Exception in nested spawn inside Fiber
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Exception in nested spawn\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Fiber: spawning\n";

        try {
            $inner = spawn(function() {
                echo "Inner: throwing\n";
                throw new Exception("inner exception");
            });

            await($inner);
            echo "Should not print\n";
        } catch (Exception $e) {
            echo "Caught in fiber: " . $e->getMessage() . "\n";
        }

        return "fiber recovered";
    });

    $fiber->start();
    $result = $fiber->getReturn();
    echo "Result: " . $result . "\n";

    return "done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Exception in nested spawn
Fiber: spawning
Inner: throwing
Caught in fiber: inner exception
Result: fiber recovered
Test completed
