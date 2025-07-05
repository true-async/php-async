--TEST--
Memory exhaustion bailout with onFinally handlers
--INI--
memory_limit=2M
--FILE--
<?php

use function Async\spawn;
use Async\Scope;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before scope\n";

$scope = new Scope();

$scope->onFinally(function() {
    echo "Finally handler 1 executed\n";
});

$scope->onFinally(function() {
    echo "Finally handler 2 executed\n";
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
Finally handler 1 executed
Finally handler 2 executed