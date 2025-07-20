--TEST--
Async\protect: cancellation applied immediately after protected block
--FILE--
<?php

use function Async\spawn;
use function Async\protect;
use function Async\await;
use function Async\suspend;

$coroutine = spawn(function() {
    echo "before protect\n";

    try {
        // This will be protected, and cancellation will be applied immediately after this block
        protect(function() {
            echo "in protect\n";
            suspend();
            echo "finished protect\n";
        });
    } catch (Async\CancellationError $e) {
        echo "caught exception: " . $e->getMessage() . "\n";
    }
});

suspend();

// Cancel the coroutine
$coroutine->cancel();

await($coroutine);

?>
--EXPECTF--
before protect
in protect
finished protect
caught exception: %s