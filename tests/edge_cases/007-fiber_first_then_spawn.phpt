--TEST--
Fiber created first, then spawn operation - should detect incompatible context
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Test: Fiber first, then spawn\n";

try {
    $fiber = new Fiber(function() {
        echo "Inside Fiber\n";
        
        // This should cause issues - spawning from within a Fiber context
        $coroutine = spawn(function() {
            echo "Inside spawned coroutine from Fiber\n";
            suspend();
            echo "Coroutine completed\n";
        });
        
        echo "Fiber attempting to continue after spawn\n";
        Fiber::suspend("fiber suspended");
        echo "Fiber resumed\n";
        
        return "fiber done";
    });
    
    echo "Starting Fiber\n";
    $result = $fiber->start();
    echo "Fiber suspended with: " . $result . "\n";
    
    echo "Resuming Fiber\n";
    $result = $fiber->resume("resume value");
    echo "Fiber returned: " . $result . "\n";
    
} catch (Async\AsyncException $e) {
    echo "Async exception caught: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Exception caught: " . $e->getMessage() . "\n";
}

echo "Test completed\n";
?>
--EXPECTF--
Test: Fiber first, then spawn
Starting Fiber
Inside Fiber
Async exception caught: The True Async Scheduler cannot be started from within a Fiber
Test completed