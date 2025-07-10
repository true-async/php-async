--TEST--
Async\protect: multiple cancellation attempts during protected block
--FILE--
<?php

use function Async\spawn;
use function Async\protect;
use function Async\await;
use function Async\suspend;

$coroutine = spawn(function() {
    echo "coroutine start\n";
    
    protect(function() {
        echo "protected block\n";
        for ($i = 1; $i <= 2; $i++) {
            echo "work: $i\n";
            suspend(); // Simulate work
        }
    });
    
    echo "after protect\n";
});

suspend();

// Try to cancel multiple times
$coroutine->cancel();
suspend();
$coroutine->cancel();
suspend();
$coroutine->cancel();

await($coroutine);

?>
--EXPECTF--
coroutine start
protected block
work: 1
work: 2