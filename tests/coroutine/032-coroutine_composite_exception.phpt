--TEST--
CompositeException with multiple finally handlers
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "start\n";

$composite_coroutine = spawn(function() {
    echo "composite coroutine started\n";
    
    $coroutine = \Async\Coroutine::getCurrent();
    
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
    throw new \RuntimeException("Main coroutine error");
});

suspend(); // Let it start and suspend
suspend(); // Let it throw

try {
    $result = $composite_coroutine->getResult();
    echo "should not get result\n";
} catch (\Async\CompositeException $e) {
    echo "caught CompositeException with " . count($e->getErrors()) . " errors\n";
    foreach ($e->getErrors() as $index => $error) {
        echo "error $index: " . get_class($error) . ": " . $error->getMessage() . "\n";
    }
} catch (Throwable $e) {
    echo "unexpected exception: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
composite coroutine started
finally 3 executing
finally 2 executing
finally 1 executing
caught CompositeException with %d errors
error %d: %s: %s
error %d: %s: %s
error %d: %s: %s
error %d: %s: %s
end