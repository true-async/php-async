--TEST--
Await operation on already completed coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

echo "start\n";

// Test 1: await on coroutine that completed successfully
$coroutine1 = spawn(function() {
    echo "coroutine1 executing\n";
    return "success_result";
});

// Wait for completion
suspend();

echo "coroutine1 finished: " . ($coroutine1->isFinished() ? "true" : "false") . "\n";

$result1 = await($coroutine1);
echo "await result: $result1\n";

// Test 2: await on coroutine that completed with exception
$coroutine2 = spawn(function() {
    echo "coroutine2 executing\n";
    throw new \RuntimeException("Coroutine error");
});

// Wait for completion
suspend();

echo "coroutine2 finished: " . ($coroutine2->isFinished() ? "true" : "false") . "\n";

try {
    $result2 = await($coroutine2);
    echo "await should not succeed\n";
} catch (\RuntimeException $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "caught unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Test 3: await on coroutine returning null
$coroutine3 = spawn(function() {
    echo "coroutine3 executing\n";
    return null;
});

// Wait for completion
suspend();

$result3 = await($coroutine3);
echo "await null result: " . (is_null($result3) ? "null" : $result3) . "\n";

echo "end\n";

?>
--EXPECTF--
start
coroutine1 executing
coroutine1 finished: true
await result: success_result
coroutine2 executing
coroutine2 finished: true
caught exception: Coroutine error
coroutine3 executing
await null result: null
end