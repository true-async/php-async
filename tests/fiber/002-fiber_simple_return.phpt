--TEST--
Fiber with simple return without suspend
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Fiber simple return without suspend\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Fiber executing\n";
        return "result from fiber";
    });

    $result = $fiber->start();
    echo "Got: " . $result . "\n";

    return "coroutine done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Fiber simple return without suspend
Fiber executing
Got: result from fiber
Test completed
