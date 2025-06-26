--TEST--
Scope onFinally composite exception handling
--FILE--
<?php

use Async\Scope;
use Async\CompositeException;

function test_multiple_exceptions_in_finally() {
    $scope = new Scope();
    
    // Add multiple finally handlers that will throw exceptions
    $scope->onFinally(function($scope) {
        throw new Exception("First exception");
    });
    
    $scope->onFinally(function($scope) {
        throw new RuntimeException("Second exception");
    });
    
    $scope->onFinally(function($scope) {
        throw new InvalidArgumentException("Third exception");
    });
    
    try {
        $scope->dispose();
    } catch (CompositeException $e) {
        echo "Caught CompositeException\n";
        echo "Number of exceptions: " . count($e->getExceptions()) . "\n";
        
        $exceptions = $e->getExceptions();
        foreach ($exceptions as $i => $exception) {
            echo "Exception " . ($i + 1) . ": " . get_class($exception) . " - " . $exception->getMessage() . "\n";
        }
        return true;
    } catch (Exception $e) {
        echo "Caught unexpected exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
        return false;
    }
    
    echo "No exception was thrown\n";
    return false;
}

function test_single_exception_in_finally() {
    $scope = new Scope();
    
    // Add single finally handler that will throw exception
    $scope->onFinally(function($scope) {
        throw new Exception("Single exception");
    });
    
    try {
        $scope->dispose();
    } catch (CompositeException $e) {
        echo "Caught CompositeException for single exception\n";
        echo "Number of exceptions: " . count($e->getExceptions()) . "\n";
        return true;
    } catch (Exception $e) {
        echo "Caught single exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
        return true;
    }
    
    echo "No exception was thrown\n";
    return false;
}

function test_no_exceptions_in_finally() {
    $scope = new Scope();
    
    $executed = false;
    $scope->onFinally(function($scope) use (&$executed) {
        $executed = true;
        echo "Finally handler executed without exception\n";
    });
    
    try {
        $scope->dispose();
        echo "No exception thrown, executed: " . ($executed ? "true" : "false") . "\n";
        return true;
    } catch (Exception $e) {
        echo "Unexpected exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
        return false;
    }
}

echo "Test 1: Multiple exceptions\n";
test_multiple_exceptions_in_finally();

echo "\nTest 2: Single exception\n";  
test_single_exception_in_finally();

echo "\nTest 3: No exceptions\n";
test_no_exceptions_in_finally();

?>
--EXPECT--
Test 1: Multiple exceptions
Caught CompositeException
Number of exceptions: 3
Exception 1: Exception - First exception
Exception 2: RuntimeException - Second exception
Exception 3: InvalidArgumentException - Third exception

Test 2: Single exception
Caught CompositeException for single exception
Number of exceptions: 1

Test 3: No exceptions
Finally handler executed without exception
No exception thrown, executed: true