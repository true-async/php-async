--TEST--
Stack overflow bailout in nested async operations
--INI--
opcache.jit_hot_func=0
--SKIPIF--
<?php
$zend_mm_enabled = getenv("USE_ZEND_ALLOC");
if ($zend_mm_enabled === "0") {
    die("skip Zend MM disabled");
}
?>
--FILE--
<?php

use function Async\spawn;

function deepRecursion($depth = 0) {
    return deepRecursion($depth + 1);
}

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

spawn(function() {
    echo "Outer async started\n";

    spawn(function() {
        echo "Inner async started\n";
        deepRecursion();
        echo "Inner async after stack overflow (should not reach)\n";
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
Shutdown function called
