--TEST--
Coroutine: onFinally() - basic usage with callback execution
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

$called = false;

$coroutine = spawn(function() {
    return "test result";
});

$coroutine->onFinally(function() use (&$called) {
    $called = true;
    echo "Finally callback executed\n";
});

await($coroutine);

// Give finally handler time to execute
suspend();

echo "Result: " . $coroutine->getResult() . "\n";
echo "Finally called: " . ($called ? "yes" : "no") . "\n";

?>
--EXPECT--
Finally callback executed
Result: test result
Finally called: yes