--TEST--
Stack overflow bailout with suspend in recursion
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
use function Async\suspend;

function deepRecursionWithSuspend($depth = 0) {
    if ($depth % 100 === 0) {
        suspend();
    }
    return deepRecursionWithSuspend($depth + 1);
}

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before spawn\n";

spawn(function() {
    echo "Before stack overflow with suspend\n";
    deepRecursionWithSuspend();
    echo "After stack overflow (should not reach)\n";
});

spawn(function() {
    echo "Other coroutine running\n";
});

echo "After spawn\n";

?>
--EXPECTF--
Before spawn
After spawn
Before stack overflow with suspend
Other coroutine running

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called