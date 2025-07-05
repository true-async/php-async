--TEST--
Async\protect: multiple cancellation attempts during protected block
--FILE--
<?php

use function Async\spawn;
use function Async\protect;
use function Async\await;

$coroutine = spawn(function() {
    echo "coroutine start\n";
    
    protect(function() {
        echo "protected block\n";
        
        // Simulate longer work
        for ($i = 0; $i < 2; $i++) {
            echo "work: $i\n";
        }
    });
    
    echo "after protect\n";
});

// Try to cancel multiple times
$coroutine->cancel();
$coroutine->cancel();
$coroutine->cancel();

try {
    await($coroutine);
} catch (Exception $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

?>
--EXPECTF--
coroutine start
protected block
work: 0
work: 1
after protect
caught exception: %s