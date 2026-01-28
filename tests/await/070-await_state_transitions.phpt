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

await($completed_coroutine);

echo "coroutine completed: " . ($completed_coroutine->isCompleted() ? "true" : "false") . "\n";

// Try to cancel already completed coroutine
$completed_coroutine->cancel(new \Async\CancellationError("Too late"));
echo "attempted to cancel completed coroutine\n";

// Await should still return original result
$result1 = await($completed_coroutine);
echo "await completed result: $result1\n";

// Test 2: Await coroutine that completed with exception, then cancel
$exception_coroutine = spawn(function() {
    echo "exception coroutine executing\n";
    throw new \RuntimeException("Original error");
});

$original_exception = null;

try {
    await($exception_coroutine);
} catch (\RuntimeException $e) {
    $original_exception = $e;
}

echo "exception coroutine completed: " . ($exception_coroutine->isCompleted() ? "true" : "false") . "\n";

// Try to cancel coroutine that already failed
$exception_coroutine->cancel(new \Async\CancellationError("Post-error cancel"));
echo "attempted to cancel failed coroutine\n";

// Should still get original exception
try {
    $result2 = await($exception_coroutine);
    echo "should not succeed\n";
} catch (\RuntimeException $e) {
    if($e === $original_exception) {
        echo "original exception preserved: " . $e->getMessage() . "\n";
    } else {
        echo "unexpected exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
} catch (\Async\CancellationError $e) {
    echo "unexpected cancellation: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
completed coroutine executing
coroutine completed: true
attempted to cancel completed coroutine
await completed result: already_done
exception coroutine executing
exception coroutine completed: true
attempted to cancel failed coroutine
original exception preserved: Original error
end