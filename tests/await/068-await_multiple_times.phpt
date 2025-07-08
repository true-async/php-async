--TEST--
Multiple await operations on same coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

echo "start\n";

// Test 1: multiple awaits on successful coroutine
$coroutine1 = spawn(function() {
    echo "coroutine1 executing\n";
    suspend();
    return "shared_result";
});

echo "first await starting\n";
$result1a = await($coroutine1);
echo "first await result: $result1a\n";

echo "second await starting\n";
$result1b = await($coroutine1);
echo "second await result: $result1b\n";

echo "third await starting\n";
$result1c = await($coroutine1);
echo "third await result: $result1c\n";

// Test 2: multiple awaits on coroutine with exception
$coroutine2 = spawn(function() {
    echo "coroutine2 executing\n";
    suspend();
    throw new \RuntimeException("Shared error");
});

echo "first await on exception coroutine\n";
try {
    $result2a = await($coroutine2);
    echo "should not succeed\n";
} catch (\RuntimeException $e) {
    echo "first caught: " . $e->getMessage() . "\n";
}

echo "second await on exception coroutine\n";
try {
    $result2b = await($coroutine2);
    echo "should not succeed\n";
} catch (\RuntimeException $e) {
    echo "second caught: " . $e->getMessage() . "\n";
}

// Test 3: multiple awaits on cancelled coroutine
$coroutine3 = spawn(function() {
    echo "coroutine3 executing\n";
    suspend();
    return "never_reached";
});

$coroutine3->cancel(new \Async\CancellationException("Shared cancellation"));

echo "first await on cancelled coroutine\n";
try {
    $result3a = await($coroutine3);
    echo "should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "first caught cancellation: " . $e->getMessage() . "\n";
}

echo "second await on cancelled coroutine\n";
try {
    $result3b = await($coroutine3);
    echo "should not succeed\n";
} catch (\Async\CancellationException $e) {
    echo "second caught cancellation: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
first await starting
coroutine1 executing
first await result: shared_result
second await starting
second await result: shared_result
third await starting
third await result: shared_result
first await on exception coroutine
coroutine2 executing
first caught: Shared error
second await on exception coroutine
second caught: Shared error
coroutine3 executing
first await on cancelled coroutine
first caught cancellation: Shared cancellation
second await on cancelled coroutine
second caught cancellation: Shared cancellation
end