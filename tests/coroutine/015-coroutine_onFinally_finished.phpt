--TEST--
Coroutine: onFinally() - throws error when coroutine is finished
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    return "test";
});

await($coroutine);

try {
    $coroutine->onFinally(function() {
        echo "Should not be called\n";
    });
    echo "Should not reach here\n";
} catch (Error $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

?>
--EXPECT--
Caught: Cannot add finally handler to a finished coroutine