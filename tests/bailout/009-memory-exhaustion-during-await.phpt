--TEST--
Memory exhaustion bailout during await operation
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
use function Async\await;

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

$coroutine = spawn(function() {
    echo "Coroutine started\n";
    str_repeat('x', 10000000);
    echo "Coroutine after memory exhaustion (should not reach)\n";
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