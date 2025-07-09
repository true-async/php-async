--TEST--
Scope: exception handlers - actual execution and propagation
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;
use Async\Scope;

echo "start\n";

// Test actual exception handler execution
$scope = Scope::inherit();

$exceptions_handled = [];

// Set up exception handler
$scope->setExceptionHandler(function($receivedScope, $coroutine, $exception) use ($scope, &$exceptions_handled) {
    echo "exception handler called\n";
    echo "scope match: " . ($receivedScope === $scope ? "true" : "false") . "\n";
    echo "coroutine type: " . get_class($coroutine) . "\n";
    echo "exception message: " . $exception->getMessage() . "\n";
    $exceptions_handled[] = $exception->getMessage();
});

// Spawn coroutines that will throw exceptions
$error_coroutine1 = $scope->spawn(function() {
    echo "error coroutine1 started\n";
    suspend();
    throw new \RuntimeException("Error from coroutine1");
});

$error_coroutine2 = $scope->spawn(function() {
    echo "error coroutine2 started\n";
    suspend();
    throw new \InvalidArgumentException("Error from coroutine2");
});

$normal_coroutine = $scope->spawn(function() {
    echo "normal coroutine started\n";
    suspend();
    echo "normal coroutine finished\n";
    return "normal_result";
});

echo "spawned coroutines\n";

// Let all coroutines run
suspend();
suspend();

echo "waiting for completion\n";
$normal_result = await($normal_coroutine);
echo "normal result: " . $normal_result . "\n";

echo "exceptions handled count: " . count($exceptions_handled) . "\n";
foreach ($exceptions_handled as $msg) {
    echo "handled: " . $msg . "\n";
}

echo "scope finished: " . ($scope->isFinished() ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECT--
start
spawned coroutines
error coroutine1 started
error coroutine2 started
normal coroutine started
exception handler called
scope match: true
coroutine type: Async\Coroutine
exception message: Error from coroutine1
exception handler called
scope match: true
coroutine type: Async\Coroutine
exception message: Error from coroutine2
normal coroutine finished
waiting for completion
normal result: normal_result
exceptions handled count: 2
handled: Error from coroutine1
handled: Error from coroutine2
scope finished: true
end