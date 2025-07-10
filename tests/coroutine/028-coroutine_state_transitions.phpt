--TEST--
Coroutine state transitions and edge cases
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "start\n";

// Test 1: isQueued() functionality
$queued_coroutine = spawn(function() {
    echo "queued coroutine executing\n";
    suspend();
    return "queued_result";
});

echo "before suspend - isQueued: " . ($queued_coroutine->isQueued() ? "true" : "false") . "\n";
echo "before suspend - isStarted: " . ($queued_coroutine->isStarted() ? "true" : "false") . "\n";

suspend(); // Let coroutine start

echo "after start - isQueued: " . ($queued_coroutine->isQueued() ? "true" : "false") . "\n";
echo "after start - isStarted: " . ($queued_coroutine->isStarted() ? "true" : "false") . "\n";
echo "after start - isSuspended: " . ($queued_coroutine->isSuspended() ? "true" : "false") . "\n";

// Test 2: getResult() on non-finished coroutine states
$running_coroutine = spawn(function() {
    echo "running coroutine started\n";
    suspend();
    echo "running coroutine continuing\n";
    return "running_result";
});

suspend(); // Let it start and suspend

echo "suspended state - isFinished: " . ($running_coroutine->isFinished() ? "true" : "false") . "\n";

try {
    $result = $running_coroutine->getResult();
    echo "getResult: ";
    var_dump($result);
} catch (\Error $e) {
    echo "getResult on suspended failed: " . get_class($e) . "\n";
} catch (Throwable $e) {
    echo "getResult unexpected: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

// Test 3: getException() on various states
$exception_coroutine = spawn(function() {
    echo "exception coroutine started\n";
    suspend();
    throw new \RuntimeException("Test exception");
});

try {
    await($exception_coroutine);
} catch (\RuntimeException $e) {
} catch (Throwable $e) {
    echo "Unexpected exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "before exception - getException: ";
try {
    $exception = $exception_coroutine->getException();
    echo ($exception ? get_class($exception) : "null") . "\n";
} catch (\Error $e) {
    echo "Error: " . get_class($e) . "\n";
}

suspend(); // Let it throw

echo "after exception - getException: ";
try {
    $exception = $exception_coroutine->getException();
    echo get_class($exception) . ": " . $exception->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Unexpected: " . get_class($e) . "\n";
}

// Test 4: isCancellationRequested() functionality
$cancel_request_coroutine = spawn(function() {
    echo "cancel request coroutine started\n";
    suspend();
    echo "cancel request coroutine continuing\n";
    return "cancel_result";
});

suspend(); // Let it start

echo "before cancel request - isCancellationRequested: " . ($cancel_request_coroutine->isCancellationRequested() ? "true" : "false") . "\n";
echo "before cancel request - isCancelled: " . ($cancel_request_coroutine->isCancelled() ? "true" : "false") . "\n";

$cancel_request_coroutine->cancel(new \Async\CancellationException("Test cancellation"));

echo "after cancel request - isCancellationRequested: " . ($cancel_request_coroutine->isCancellationRequested() ? "true" : "false") . "\n";

suspend(); // Let cancellation propagate

echo "after cancel request - isCancelled: " . ($cancel_request_coroutine->isCancelled() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECTF--
start
before suspend - isQueued: true
before suspend - isStarted: false
queued coroutine executing
after start - isQueued: true
after start - isStarted: true
after start - isSuspended: true
running coroutine started
suspended state - isFinished: false
getResult: NULL
running coroutine continuing
exception coroutine started
before exception - getException: RuntimeException
after exception - getException: RuntimeException: Test exception
cancel request coroutine started
before cancel request - isCancellationRequested: false
before cancel request - isCancelled: false
after cancel request - isCancellationRequested: true
after cancel request - isCancelled: true
end