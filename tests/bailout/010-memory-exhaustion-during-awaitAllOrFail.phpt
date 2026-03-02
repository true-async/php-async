--TEST--
Memory exhaustion bailout during awaitAllOrFail operation
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
use function Async\await_all_or_fail;

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

echo "Before awaitAllOrFail\n";
$results = await_all_or_fail($coroutines);
echo "After awaitAllOrFail (should not reach)\n";

?>
--EXPECTF--
Before spawn
Before awaitAllOrFail
Coroutine 1 started
Coroutine 2 started

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Attempt to finalize a coroutine that is still in the queue in Unknown on line 0
Shutdown function called