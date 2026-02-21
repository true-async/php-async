--TEST--
Coroutine finally multiple exceptions handling
--FILE--
<?php

use function Async\spawn;
use Async\CompositeException;
use Async\Scope;

$scope = new Scope();

$scope->setExceptionHandler(function($scope, $coroutine, $exception) {
    if ($exception instanceof CompositeException) {
        echo "Caught CompositeException\n";
        echo "Number of exceptions: " . count($exception->getExceptions()) . "\n";
        
        $exceptions = $exception->getExceptions();
        foreach ($exceptions as $i => $ex) {
            echo "Exception " . ($i + 1) . ": " . get_class($ex) . " - " . $ex->getMessage() . "\n";
        }
    } else {
        echo "Caught single exception: " . get_class($exception) . " - " . $exception->getMessage() . "\n";
    }
});

$coro = $scope->spawn(function() {
    throw new Exception("Original exception");
});

// Add multiple finally handlers that will throw exceptions
$coro->finally(function($coroutine) {
    throw new Exception("First exception");
});

$coro->finally(function($coroutine) {
    throw new RuntimeException("Second exception"); 
});

$coro->finally(function($coroutine) {
    throw new InvalidArgumentException("Third exception");
});

?>
--EXPECT--
Caught single exception: Exception - Original exception
Caught CompositeException
Number of exceptions: 3
Exception 1: Exception - First exception
Exception 2: RuntimeException - Second exception
Exception 3: InvalidArgumentException - Third exception