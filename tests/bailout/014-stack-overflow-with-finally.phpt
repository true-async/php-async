--TEST--
Stack overflow bailout with onFinally handlers
--FILE--
<?php

use function Async\spawn;
use Async\Scope;

function deepRecursion($depth = 0) {
    return deepRecursion($depth + 1);
}

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before scope\n";

$scope = new Scope();

$scope->onFinally(function() {
    echo "Finally handler executed\n";
});

$coroutine = $scope->spawn(function() {
    echo "Before stack overflow\n";
    deepRecursion();
    echo "After stack overflow (should not reach)\n";
    return "result";
});

echo "After spawn\n";

?>
--EXPECTF--
Before scope
After spawn
Before stack overflow

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called
Finally handler executed