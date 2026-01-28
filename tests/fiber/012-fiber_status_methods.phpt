--TEST--
Fiber status methods
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Fiber status methods\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        Fiber::suspend("s1");
        Fiber::suspend("s2");
        return "done";
    });

    echo "=== Before start ===\n";
    echo "isStarted: " . ($fiber->isStarted() ? "Y" : "N") . "\n";
    echo "isSuspended: " . ($fiber->isSuspended() ? "Y" : "N") . "\n";
    echo "isRunning: " . ($fiber->isRunning() ? "Y" : "N") . "\n";
    echo "isTerminated: " . ($fiber->isTerminated() ? "Y" : "N") . "\n";

    $fiber->start();

    echo "=== After suspend ===\n";
    echo "isStarted: " . ($fiber->isStarted() ? "Y" : "N") . "\n";
    echo "isSuspended: " . ($fiber->isSuspended() ? "Y" : "N") . "\n";
    echo "isRunning: " . ($fiber->isRunning() ? "Y" : "N") . "\n";
    echo "isTerminated: " . ($fiber->isTerminated() ? "Y" : "N") . "\n";

    $fiber->resume();
    $fiber->resume();

    echo "=== After terminated ===\n";
    echo "isStarted: " . ($fiber->isStarted() ? "Y" : "N") . "\n";
    echo "isSuspended: " . ($fiber->isSuspended() ? "Y" : "N") . "\n";
    echo "isRunning: " . ($fiber->isRunning() ? "Y" : "N") . "\n";
    echo "isTerminated: " . ($fiber->isTerminated() ? "Y" : "N") . "\n";

    return "complete";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Fiber status methods
=== Before start ===
isStarted: N
isSuspended: N
isRunning: N
isTerminated: N
=== After suspend ===
isStarted: Y
isSuspended: Y
isRunning: N
isTerminated: N
=== After terminated ===
isStarted: Y
isSuspended: N
isRunning: N
isTerminated: Y
Test completed
