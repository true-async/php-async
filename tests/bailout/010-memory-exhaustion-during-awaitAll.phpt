--TEST--
Memory exhaustion bailout during awaitAll operation
--INI--
memory_limit=2M
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

$coroutines = [
    spawn(function() {
        echo "Coroutine 1 started\n";
        return "result1";
    }),
    spawn(function() {
        echo "Coroutine 2 started\n";
        str_repeat('x', 10000000);
        echo "Coroutine 2 after memory exhaustion (should not reach)\n";
        return "result2";
    }),
    spawn(function() {
        echo "Coroutine 3 started\n";
        return "result3";
    }),
];

echo "Before awaitAll\n";
$results = awaitAll($coroutines);
echo "After awaitAll (should not reach)\n";

?>
--EXPECTF--
Before spawn
Before awaitAll
Coroutine 1 started
Coroutine 2 started

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called