--TEST--
Coroutine: getException() - throws RuntimeException if running
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

try {
    $coroutine->getException();
    echo "Should not reach here\n";
} catch (RuntimeException $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

?>
--EXPECT--
Caught: Cannot get exception of a running coroutine