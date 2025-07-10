--TEST--
Scheduler: shutdown functionality and cleanup
--FILE--
<?php

use function Async\spawn;
use function Async\gracefulShutdown;
use function Async\suspend;
use function Async\awaitAll;

echo "start\n";

// Test 1: Basic scheduler shutdown
$coroutine1 = spawn(function() {
    echo "coroutine1 running\n";
    suspend();
    echo "coroutine1 after suspend\n";
    return "result1";
});

$coroutine2 = spawn(function() {
    echo "coroutine2 running\n";
    suspend();
    echo "coroutine2 after suspend\n";
    return "result2";
});

echo "coroutines spawned\n";

// Trigger graceful shutdown
try {
    gracefulShutdown();
    awaitAll([$coroutine1, $coroutine2]);
} catch (Throwable $e) {
    echo "shutdown exception: " . $e->getMessage() . "\n";
}

// Check coroutine states after shutdown
echo "coroutine1 finished: " . ($coroutine1->isFinished() ? "true" : "false") . "\n";
echo "coroutine2 finished: " . ($coroutine2->isFinished() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECTF--
start
coroutines spawned
coroutine1 running
coroutine2 running
coroutine1 after suspend
coroutine2 after suspend
coroutine1 finished: true
coroutine2 finished: true
end