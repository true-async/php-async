--TEST--
CompositeException with multiple finally handlers
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\current_coroutine;
use function Async\await;

echo "start\n";

$scope = new \Async\Scope();
$scope->setExceptionHandler(function($scope, $coroutine, $exception) {

    if(!$exception instanceof \Async\CompositeException) {
        echo "caught exception: {$exception->getMessage()}\n";
        return;
    }

    foreach ($exception->getExceptions() as $i => $error) {
        $type = get_class($error);
        echo "error {$i}: {$type}: {$error->getMessage()}\n";
    }
});

$composite_coroutine = $scope->spawn(function() {
    echo "composite coroutine started\n";
    
    $coroutine = \Async\current_coroutine();
    
    // Add multiple finally handlers that throw
    $coroutine->onFinally(function() {
        echo "finally 1 executing\n";
        throw new \RuntimeException("Finally 1 error");
    });
    
    $coroutine->onFinally(function() {
        echo "finally 2 executing\n";
        throw new \InvalidArgumentException("Finally 2 error");
    });
    
    $coroutine->onFinally(function() {
        echo "finally 3 executing\n";
        throw new \LogicException("Finally 3 error");
    });
    
    suspend();
    throw new \RuntimeException("coroutine error");
});

try {
    await($composite_coroutine);
} catch (Throwable $e) {
    echo "caught: {$e->getMessage()}\n";
}

echo "end\n";

?>
--EXPECTF--
start
composite coroutine started
finally 1 executing
finally 2 executing
finally 3 executing
error 0: RuntimeException: Finally 1 error
error 1: InvalidArgumentException: Finally 2 error
error 2: LogicException: Finally 3 error
caught: coroutine error
end