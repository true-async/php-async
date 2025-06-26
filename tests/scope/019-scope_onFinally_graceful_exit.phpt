--TEST--
Scope onFinally graceful exit behavior
--FILE--
<?php

use Async\Scope;
use Async\CompositeException;

function test_graceful_exit_interrupts_composite() {
    $scope = new Scope();
    
    $handlers_executed = [];
    
    // Add multiple finally handlers
    $scope->onFinally(function($scope) use (&$handlers_executed) {
        $handlers_executed[] = "first";
        throw new Exception("First exception");
    });
    
    $scope->onFinally(function($scope) use (&$handlers_executed) {
        $handlers_executed[] = "second";
        // This simulates a graceful exit - though in real code 
        // this would come from internal PHP mechanisms like exit()
        throw new RuntimeException("Second exception");
    });
    
    $scope->onFinally(function($scope) use (&$handlers_executed) {
        $handlers_executed[] = "third";
        throw new InvalidArgumentException("Third exception");
    });
    
    try {
        $scope->dispose();
    } catch (CompositeException $e) {
        echo "Caught CompositeException\n";
        echo "Number of exceptions: " . count($e->getExceptions()) . "\n";
        echo "Handlers executed: " . implode(", ", $handlers_executed) . "\n";
        return true;
    } catch (Exception $e) {
        echo "Caught exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
        echo "Handlers executed: " . implode(", ", $handlers_executed) . "\n";
        return false;
    }
    
    echo "No exception was thrown\n";
    echo "Handlers executed: " . implode(", ", $handlers_executed) . "\n";
    return false;
}

function test_mixed_exceptions_and_successful_handlers() {
    $scope = new Scope();
    
    $handlers_executed = [];
    
    // Mix of successful and failing handlers
    $scope->onFinally(function($scope) use (&$handlers_executed) {
        $handlers_executed[] = "successful1";
        // No exception
    });
    
    $scope->onFinally(function($scope) use (&$handlers_executed) {
        $handlers_executed[] = "exception1";
        throw new Exception("Exception 1");
    });
    
    $scope->onFinally(function($scope) use (&$handlers_executed) {
        $handlers_executed[] = "successful2";
        // No exception
    });
    
    $scope->onFinally(function($scope) use (&$handlers_executed) {
        $handlers_executed[] = "exception2";
        throw new RuntimeException("Exception 2");
    });
    
    try {
        $scope->dispose();
    } catch (CompositeException $e) {
        echo "Caught CompositeException\n";
        echo "Number of exceptions: " . count($e->getExceptions()) . "\n";
        echo "Handlers executed: " . implode(", ", $handlers_executed) . "\n";
        
        $exceptions = $e->getExceptions();
        foreach ($exceptions as $i => $exception) {
            echo "Exception " . ($i + 1) . ": " . get_class($exception) . " - " . $exception->getMessage() . "\n";
        }
        return true;
    } catch (Exception $e) {
        echo "Caught single exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
        echo "Handlers executed: " . implode(", ", $handlers_executed) . "\n";
        return false;
    }
    
    echo "No exception was thrown\n";
    echo "Handlers executed: " . implode(", ", $handlers_executed) . "\n";
    return false;
}

echo "Test 1: Basic composite exception behavior\n";
test_graceful_exit_interrupts_composite();

echo "\nTest 2: Mixed successful and failing handlers\n";
test_mixed_exceptions_and_successful_handlers();

?>
--EXPECT--
Test 1: Basic composite exception behavior
Caught CompositeException
Number of exceptions: 3
Handlers executed: first, second, third

Test 2: Mixed successful and failing handlers
Caught CompositeException
Number of exceptions: 2
Handlers executed: successful1, exception1, successful2, exception2
Exception 1: Exception - Exception 1
Exception 2: RuntimeException - Exception 2