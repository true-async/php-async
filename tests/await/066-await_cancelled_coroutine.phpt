--TEST--
Await operation on explicitly cancelled coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

echo "start\n";

// Test 1: await on coroutine cancelled before execution
$coroutine1 = spawn(function() {
    echo "coroutine1 should not execute\n";
    suspend();
    return "result1";
});

$coroutine1->cancel(new \Async\CancellationException("Manual cancellation"));
echo "coroutine1 cancelled\n";

try {
    $result1 = await($coroutine1);
    echo "await should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "caught cancellation: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "caught unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Test 2: await on coroutine cancelled during execution
$coroutine2 = spawn(function() {
    echo "coroutine2 started\n";
    suspend();
    echo "coroutine2 should not complete\n";
    return "result2";
});

// Let coroutine start
suspend();

$coroutine2->cancel(new \Async\CancellationException("Cancelled during execution"));
echo "coroutine2 cancelled during execution\n";

try {
    $result2 = await($coroutine2);
    echo "await should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "caught cancellation: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "caught unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
coroutine1 cancelled
caught cancellation: Manual cancellation
coroutine2 started
coroutine2 cancelled during execution
caught cancellation: Cancelled during execution
end