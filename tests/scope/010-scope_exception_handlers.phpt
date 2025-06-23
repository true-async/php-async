--TEST--
Scope: setExceptionHandler() and setChildScopeExceptionHandler() - basic usage
--FILE--
<?php

use Async\Scope;

$scope = new Scope();

$scope->setExceptionHandler(function($exception) {
    echo "Exception handled: " . $exception->getMessage() . "\n";
});

$scope->setChildScopeExceptionHandler(function($exception) {
    echo "Child scope exception handled: " . $exception->getMessage() . "\n";
});

echo "Exception handlers set successfully\n";

?>
--EXPECT--
Exception handlers set successfully