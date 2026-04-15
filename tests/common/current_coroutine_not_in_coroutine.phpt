--TEST--
Async\current_coroutine(): throws at the script root (no current coroutine)
--FILE--
<?php

use function Async\current_coroutine;

// Covers async.c PHP_FUNCTION(Async_current_coroutine) L772-775:
// zend_async_throw("The current coroutine is not defined") when called
// outside any coroutine context (main script root).

try {
    current_coroutine();
    echo "no-throw\n";
} catch (\Throwable $e) {
    echo "caught: ", $e->getMessage(), "\n";
}

?>
--EXPECT--
caught: The current coroutine is not defined
