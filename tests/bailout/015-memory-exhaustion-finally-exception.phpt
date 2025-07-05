--TEST--
Memory exhaustion bailout with exception in onFinally handler
--SKIPIF--
<?php
$zend_mm_enabled = getenv("USE_ZEND_ALLOC");
if ($zend_mm_enabled === "0") {
    die("skip Zend MM disabled");
}
?>
--INI--
memory_limit=2M
--FILE--
<?php

use function Async\spawn;
use Async\Scope;
use Async\CompositeException;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before scope\n";

$scope = new Scope();

$scope->setExceptionHandler(function($scope, $coroutine, $exception) {
    if ($exception instanceof CompositeException) {
        echo "Caught CompositeException with " . count($exception->getExceptions()) . " exceptions\n";
        $exceptions = $exception->getExceptions();
        foreach ($exceptions as $i => $ex) {
            echo "Exception " . ($i + 1) . ": " . $ex->getMessage() . "\n";
        }
    } else {
        echo "Caught single exception: " . $exception->getMessage() . "\n";
    }
});

$scope->onFinally(function() {
    echo "Finally handler executed\n";
    throw new RuntimeException("Exception in finally handler");
});

$coroutine = $scope->spawn(function() {
    echo "Before memory exhaustion\n";
    str_repeat('x', 10000000);
    echo "After memory exhaustion (should not reach)\n";
    return "result";
});

echo "After spawn\n";

?>
--EXPECTF--
Before scope
After spawn
Before memory exhaustion

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called
Finally handler executed
Caught single exception: Exception in finally handler