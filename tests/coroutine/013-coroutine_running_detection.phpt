--TEST--
Coroutine: isRunning() - detects currently executing coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$isRunning = false;

$coroutine = spawn(function() use (&$isRunning) {
    global $coroutine;
    $isRunning = $coroutine->isRunning();
    return "test";
});

await($coroutine);

echo "Was running during execution: " . ($isRunning ? "yes" : "no") . "\n";
echo "Is running after completion: " . ($coroutine->isRunning() ? "yes" : "no") . "\n";

?>
--EXPECT--
Was running during execution: yes
Is running after completion: no