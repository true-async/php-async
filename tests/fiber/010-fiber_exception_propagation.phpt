--TEST--
Exception propagation from Fiber to coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Exception propagation\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Fiber: suspending\n";
        Fiber::suspend("suspend");
        echo "Fiber: throwing\n";
        throw new RuntimeException("fiber exception");
    });

    try {
        $val = $fiber->start();
        echo "Got: " . $val . "\n";

        $fiber->resume("resume");
        echo "Should not print\n";
    } catch (RuntimeException $e) {
        echo "Caught in coroutine: " . $e->getMessage() . "\n";
    }

    return "done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Exception propagation
Fiber: suspending
Got: suspend
Fiber: throwing
Caught in coroutine: fiber exception
Test completed
