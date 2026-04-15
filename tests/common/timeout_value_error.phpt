--TEST--
Async\timeout(): ValueError on non-positive duration
--FILE--
<?php

use function Async\timeout;

// Covers async.c PHP_FUNCTION(Async_timeout) L694-697: zend_value_error
// "Timeout value must be greater than 0" for ms <= 0.

foreach ([0, -1, -1000] as $ms) {
    try {
        timeout($ms);
        echo "no-throw: $ms\n";
    } catch (\ValueError $e) {
        echo "ms=$ms: ", $e->getMessage(), "\n";
    }
}

?>
--EXPECT--
ms=0: Timeout value must be greater than 0
ms=-1: Timeout value must be greater than 0
ms=-1000: Timeout value must be greater than 0
