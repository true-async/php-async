--TEST--
UC-007: Multiple concurrent Fibers
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Concurrent Fibers\n";

$coroutine = spawn(function() {
    $fiber1 = new Fiber(function() {
        echo "F1: step 1\n";
        Fiber::suspend();
        echo "F1: step 2\n";
        Fiber::suspend();
        echo "F1: step 3\n";
        return "fiber1 done";
    });

    $fiber2 = new Fiber(function() {
        echo "F2: step 1\n";
        Fiber::suspend();
        echo "F2: step 2\n";
        return "fiber2 done";
    });

    $fiber1->start();
    $fiber2->start();

    echo "Resume F2\n";
    $r2 = $fiber2->resume();
    echo "F2 result: " . $r2 . "\n";

    echo "Resume F1 (1)\n";
    $fiber1->resume();

    echo "Resume F1 (2)\n";
    $r1 = $fiber1->resume();
    echo "F1 result: " . $r1 . "\n";

    return "done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Concurrent Fibers
F1: step 1
F2: step 1
Resume F2
F2: step 2
F2 result: fiber2 done
Resume F1 (1)
F1: step 2
Resume F1 (2)
F1: step 3
F1 result: fiber1 done
Test completed
