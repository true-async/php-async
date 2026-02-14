--TEST--
Comparison of manual cancellation vs timeout cancellation in await
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;
use function Async\timeout;
use function Async\delay;

echo "start\n";

// Test 1: Manual cancellation
$manual_coroutine = spawn(function() {
    echo "manual coroutine started\n";
    suspend();
    echo "manual coroutine should not complete\n";
    return "manual_result";
});

// Let it start
suspend();

$manual_coroutine->cancel(new \Async\AsyncCancellation("Manual cancel message"));
echo "manual coroutine cancelled\n";

try {
    $result = await($manual_coroutine);
    echo "manual await should not succeed\n";
} catch (\Async\AsyncCancellation $e) {
    echo "manual cancellation caught: " . get_class($e) . ": " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "manual unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Test 2: Timeout cancellation
$timeout_coroutine = spawn(function() {
    echo "timeout coroutine started\n";
    delay(50); // Will be cancelled by timeout
    echo "timeout coroutine should not complete\n";
    return "timeout_result";
});

echo "timeout coroutine spawned\n";

try {
    $result = await($timeout_coroutine, timeout(1));
    echo "timeout await should not succeed\n";
} catch (\Async\TimeoutException $e) {
    echo "timeout cancellation caught: " . get_class($e) . ": " . $e->getMessage() . "\n";
    $timeout_coroutine->cancel(new \Async\AsyncCancellation("Timeout after 1 milliseconds"));
} catch (\Async\AsyncCancellation $e) {
    echo "timeout as cancellation: " . get_class($e) . ": " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "timeout unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Test 3: Race condition - manual cancel vs timeout
$race_coroutine = spawn(function() {
    echo "race coroutine started\n";
    suspend();
    suspend();
    return "race_result";
});

// Start coroutine
suspend();

// Cancel manually before timeout
$race_coroutine->cancel(new \Async\AsyncCancellation("Manual wins"));

try {
    $result = await($race_coroutine, timeout(1000)); // Should get manual cancel, not timeout
    echo "race await should not succeed\n";
} catch (\Async\AsyncCancellation $e) {
    echo "race cancellation caught: " . get_class($e) . ": " . $e->getMessage() . "\n";
} catch (\Async\TimeoutException $e) {
    echo "race timeout caught: " . get_class($e) . ": " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "race unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
manual coroutine started
manual coroutine cancelled
manual cancellation caught: Async\AsyncCancellation: Manual cancel message
timeout coroutine spawned
timeout coroutine started
timeout cancellation caught: Async\TimeoutException: Timeout occurred after 1 milliseconds
race coroutine started
race cancellation caught: Async\AsyncCancellation: Manual wins
end