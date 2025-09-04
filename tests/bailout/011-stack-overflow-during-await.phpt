--TEST--
Stack overflow bailout during await operation
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
use function Async\await;

function deepRecursion($depth = 0) {
    return deepRecursion($depth + 1);
}

$function = function(bool $out = true) {
    if($out) echo "Shutdown function called\n";
};

$function(false);

register_shutdown_function($function);

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
