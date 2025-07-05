--TEST--
Async\protect: cancellation is deferred during protected block
--FILE--
<?php

use function Async\spawn;
use function Async\protect;
use function Async\await;
use function Async\suspend;

$coroutine = spawn(function() {
    echo "coroutine start\n";
    
    protect(function() {
        echo "protected block start\n";
        suspend();
        echo "protected block end\n";
    });
    
    echo "coroutine end\n";
});

suspend();

// Try to cancel the coroutine
$coroutine->cancel();

// Wait for completion
await($coroutine);

?>
--EXPECTF--
coroutine start
protected block start
protected block end