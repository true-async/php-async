--TEST--
Fiber.throw() with exception handling
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Fiber throw method\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Before suspend\n";

        try {
            Fiber::suspend("waiting");
            echo "Should not print\n";
        } catch (Exception $e) {
            echo "Caught in fiber: " . $e->getMessage() . "\n";
            return "recovered";
        }

        return "normal";
    });

    $val = $fiber->start();
    echo "Suspended: " . $val . "\n";

    $fiber->throw(new Exception("thrown error"));
    $result = $fiber->getReturn();
    echo "Result: " . $result . "\n";

    return "done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Fiber throw method
Before suspend
Suspended: waiting
Caught in fiber: thrown error
Result: recovered
Test completed
