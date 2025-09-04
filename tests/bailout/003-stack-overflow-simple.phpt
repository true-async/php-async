--TEST--
Stack overflow bailout in simple async operation
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

$function = function(bool $out = true) {
    if($out) echo "Shutdown function called\n";
};

$function(false);

register_shutdown_function($function);

echo "Before spawn\n";

spawn(function() {
    echo "Before stack overflow\n";
    deepRecursion();
    echo "After stack overflow (should not reach)\n";
});

echo "After spawn\n";

?>
--EXPECTF--
Before spawn
After spawn
Before stack overflow

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called
