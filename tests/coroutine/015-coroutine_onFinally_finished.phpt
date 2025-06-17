--TEST--
Coroutine: onFinally() - call when coroutine is already finished
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    return "test";
});

echo 'Coroutine returned: '.await($coroutine)."\n";

$coroutine->onFinally(function() {
    echo "Finally called\n";
});

?>
--EXPECT--
Coroutine returned: test
Finally called