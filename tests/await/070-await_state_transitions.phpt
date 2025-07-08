--TEST--
Complex state transitions in await operations
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

echo "start\n";

// Test 1: Cancel already completed coroutine, then await
$completed_coroutine = spawn(function() {
    echo "completed coroutine executing\n";
    return "already_done";
});

// Wait for completion
suspend();

echo "coroutine finished: " . ($completed_coroutine->isFinished() ? "true" : "false") . "\n";

// Try to cancel already completed coroutine
$completed_coroutine->cancel(new \Async\CancellationException("Too late"));
echo "attempted to cancel completed coroutine\n";

// Await should still return original result
$result1 = await($completed_coroutine);
echo "await completed result: $result1\n";

// Test 2: Await coroutine that completed with exception, then cancel
$exception_coroutine = spawn(function() {
    echo "exception coroutine executing\n";
    throw new \RuntimeException("Original error");
});

// Wait for completion
suspend();

echo "exception coroutine finished: " . ($exception_coroutine->isFinished() ? "true" : "false") . "\n";

// Try to cancel coroutine that already failed
$exception_coroutine->cancel(new \Async\CancellationException("Post-error cancel"));
echo "attempted to cancel failed coroutine\n";

// Should still get original exception
try {
    $result2 = await($exception_coroutine);
    echo "should not succeed\n";
} catch (\RuntimeException $e) {
    echo "original exception preserved: " . $e->getMessage() . "\n";
} catch (\Async\CancellationException $e) {
    echo "unexpected cancellation: " . $e->getMessage() . "\n";
}

// Test 3: Multiple operations on same coroutine
$multi_coroutine = spawn(function() {
    echo "multi coroutine started\n";
    suspend();
    suspend();
    return "multi_result";
});

// Let it start
suspend();

// First await (should work)
spawn(function() use ($multi_coroutine) {
    try {
        $result = await($multi_coroutine);
        echo "concurrent await result: $result\n";
    } catch (Throwable $e) {
        echo "concurrent await failed: " . $e->getMessage() . "\n";
    }
});

// Cancel while being awaited
$multi_coroutine->cancel(new \Async\CancellationException("Cancelled while awaited"));

// Try to await cancelled coroutine
try {
    $result3 = await($multi_coroutine);
    echo "should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "main await cancelled: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
completed coroutine executing
coroutine finished: true
attempted to cancel completed coroutine
await completed result: already_done
exception coroutine executing
exception coroutine finished: true
attempted to cancel failed coroutine
original exception preserved: Original error
multi coroutine started
concurrent await failed: Cancelled while awaited
main await cancelled: Cancelled while awaited
end