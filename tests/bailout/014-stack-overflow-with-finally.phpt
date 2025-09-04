--TEST--
Stack overflow bailout with onFinally handlers
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
use Async\Scope;

function deepRecursion($depth = 0) {
    return deepRecursion($depth + 1);
}

register_shutdown_function(function() {
    echo "Shutdown function called\n";
});

echo "Before scope\n";

$scope = new Scope();

$finally = function($x = false, $out = true) {
    if($out) {
        echo "Finally handler executed\n";
    }
};

// JIT PHP in tracing mode can compile functions on demand. When memory runs out,
// JIT crashes with an error because it tries to compile a closure.
// This code attempts to work around the issue so that the test runs correctly.
$finally(false, false);

$scope->onFinally($finally);

$coroutine = $scope->spawn(function() {
    echo "Before stack overflow\n";
    deepRecursion();
    echo "After stack overflow (should not reach)\n";
    return "result";
});

echo "After spawn\n";

?>
--EXPECTF--
Before scope
After spawn
Before stack overflow

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d

Warning: Graceful shutdown mode was started in %s on line %d
Shutdown function called
Finally handler executed
