--TEST--
Scheduler: graceful shutdown with exception handling
--FILE--
<?php

use function Async\spawn;
use function Async\gracefulShutdown;
use function Async\suspend;
use function Async\awaitAll;

echo "start\n";

// Test graceful shutdown with exceptions
$error_coroutine = spawn(function() {
    echo "error coroutine started\n";
    suspend();
    throw new \RuntimeException("Error during shutdown");
});

$cleanup_coroutine = spawn(function() {
    echo "cleanup coroutine started\n";
    suspend();
    echo "cleanup coroutine running\n";
    return "cleanup_done";
});

echo "coroutines spawned\n";

// Trigger graceful shutdown with custom cancellation
try {
    $cancellation = new \Async\CancellationException("Custom shutdown message");
    awaitAll([$error_coroutine, $cleanup_coroutine]);
    echo "graceful shutdown with custom cancellation completed\n";
} catch (\Async\CancellationException $e) {
    echo "caught shutdown cancellation: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "caught shutdown exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Check states after shutdown
echo "error coroutine finished: " . ($error_coroutine->isFinished() ? "true" : "false") . "\n";
echo "cleanup coroutine finished: " . ($cleanup_coroutine->isFinished() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECTF--
start
coroutines spawned
error coroutine started
cleanup coroutine started
cleanup coroutine running
graceful shutdown with custom cancellation completed
error coroutine finished: true
cleanup coroutine finished: true
end