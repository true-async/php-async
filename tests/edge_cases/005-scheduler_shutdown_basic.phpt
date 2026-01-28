--TEST--
Scheduler: shutdown functionality and cleanup
--FILE--
<?php

use function Async\spawn;
use function Async\graceful_shutdown;
use function Async\suspend;
use function Async\await_all;

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
    await_all([$coroutine1, $coroutine2]);
} catch (Throwable $e) {
    echo "shutdown exception: " . $e->getMessage() . "\n";
}

// Check coroutine states after shutdown
echo "coroutine1 completed: " . ($coroutine1->isCompleted() ? "true" : "false") . "\n";
echo "coroutine2 completed: " . ($coroutine2->isCompleted() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECTF--
start
coroutines spawned
coroutine1 running
coroutine2 running
coroutine1 after suspend
coroutine2 after suspend
coroutine1 completed: true
coroutine2 completed: true
end