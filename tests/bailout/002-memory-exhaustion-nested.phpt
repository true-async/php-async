--TEST--
Memory exhaustion bailout in nested async operations
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
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

spawn(function() {
    echo "Outer async started\n";
    
    spawn(function() {
        echo "Inner async started\n";
        str_repeat('x', 10000000);
        echo "Inner async after memory exhaustion (should not reach)\n";
    });
    
    echo "Outer async continues\n";
});

echo "After spawn\n";

?>
--EXPECTF--
Before spawn
After spawn
Outer async started
Outer async continues
Inner async started

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called