--TEST--
Fiber garbage collection when suspended
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Fiber GC when suspended\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Before suspend\n";
        Fiber::suspend("suspended");
        echo "Should not print\n";
    });

    $fiber->start();
    echo "Fiber suspended\n";

    // Release reference
    unset($fiber);
    gc_collect_cycles();

    echo "Fiber GC'd\n";

    return "done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Fiber GC when suspended
Before suspend
Fiber suspended
Fiber GC'd
Test completed
