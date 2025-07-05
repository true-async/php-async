--TEST--
Memory exhaustion bailout in shutdown function with async
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

register_shutdown_function(function() {
    echo "Shutdown function started\n";
    spawn(function() {
        echo "Async in shutdown started\n";
        str_repeat('x', 10000000);
        echo "After memory exhaustion in shutdown (should not reach)\n";
    });
    echo "Shutdown function continues\n";
});

echo "Before spawn\n";

spawn(function() {
    echo "Regular async operation\n";
});

echo "Script ending\n";

?>
--EXPECTF--
Before spawn
Script ending
Regular async operation
Shutdown function started
Shutdown function continues
Async in shutdown started

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d