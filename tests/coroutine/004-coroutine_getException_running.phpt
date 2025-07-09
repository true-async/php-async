--TEST--
Coroutine: getException() - throws Async\AsyncException if running
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

if($coroutine->getException() === null) {
    echo "No exception\n";
} else {
    echo "Exception: " . get_class($coroutine->getException()) . "\n";
}

?>
--EXPECT--
No exception