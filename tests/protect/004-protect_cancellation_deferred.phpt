--TEST--
Async\protect: cancellation is deferred during protected block
--FILE--
<?php

use function Async\spawn;
use function Async\protect;
use function Async\await;

$coroutine = spawn(function() {
    echo "coroutine start\n";
    
    protect(function() {
        echo "protected block start\n";
        
        // Simulate work in protected block
        for ($i = 0; $i < 3; $i++) {
            echo "protected work: $i\n";
        }
        
        echo "protected block end\n";
    });
    
    echo "coroutine end\n";
});

// Try to cancel the coroutine
$coroutine->cancel();

// Wait for completion
try {
    await($coroutine);
} catch (Exception $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

?>
--EXPECTF--
coroutine start
protected block start
protected work: 0
protected work: 1
protected work: 2
protected block end
caught exception: %s