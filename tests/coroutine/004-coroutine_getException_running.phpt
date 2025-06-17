--TEST--
Coroutine: getException() - throws Async\AsyncException if running
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

try {
    $coroutine->getException();
    echo "Should not reach here\n";
} catch (Async\AsyncException $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

?>
--EXPECT--
Caught: Cannot get exception of a running coroutine