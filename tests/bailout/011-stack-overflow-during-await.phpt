--TEST--
Stack overflow bailout during await operation
--FILE--
<?php

use function Async\spawn;
use function Async\await;

function deepRecursion($depth = 0) {
    return deepRecursion($depth + 1);
}

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

$coroutine = spawn(function() {
    echo "Coroutine started\n";
    deepRecursion();
    echo "Coroutine after stack overflow (should not reach)\n";
    return "result";
});

echo "Before await\n";
$result = await($coroutine);
echo "After await (should not reach)\n";

?>
--EXPECTF--
Before spawn
Before await
Coroutine started

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called