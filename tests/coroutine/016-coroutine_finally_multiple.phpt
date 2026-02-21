--TEST--
Coroutine: finally() - multiple handlers execution
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

$calls = [];

$coroutine = spawn(function() {
    return "test";
});

$coroutine->finally(function() use (&$calls) {
    $calls[] = "first";
    echo "First finally handler\n";
});

$coroutine->finally(function() use (&$calls) {
    $calls[] = "second";
    echo "Second finally handler\n";
});

await($coroutine);
suspend();

echo "Handlers called: " . implode(", ", $calls) . "\n";

?>
--EXPECT--
First finally handler
Second finally handler
Handlers called: first, second