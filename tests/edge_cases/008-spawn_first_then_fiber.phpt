--TEST--
Spawn coroutine first, then create Fiber - should detect context conflicts
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "Test: Spawn first, then Fiber\n";

try {
    // First spawn a coroutine that will suspend and wait
    $coroutine = spawn(function() {
        echo "Coroutine started\n";
        suspend(); // This activates the async scheduler
        echo "Coroutine resumed\n";
        return "coroutine result";
    });
    
    echo "Coroutine spawned, now creating Fiber\n";
    
    // Now try to create and use a Fiber while async scheduler is active
    $fiber = new Fiber(function() {
        echo "Inside Fiber - this should conflict with active scheduler\n";
        
        // Try to interact with the active coroutine from within Fiber
        // This creates a context conflict
        Fiber::suspend("fiber suspended");
        
        echo "Fiber resumed\n";
        return "fiber done";
    });
    
    echo "Starting Fiber\n";
    $fiberResult = $fiber->start();
    echo "Fiber suspended with: " . $fiberResult . "\n";
    
    echo "Resuming Fiber\n";
    $fiber->resume("resume data");
    $fiberResult = $fiber->getReturn();
    echo "Fiber completed with: " . $fiberResult . "\n";
    
    echo "Getting coroutine result\n";
    $coroutineResult = await($coroutine);
    echo "Coroutine completed with: " . $coroutineResult . "\n";
    
} catch (Error $e) {
    echo "Error caught: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Exception caught: " . $e->getMessage() . "\n";
}

echo "Test completed\n";
?>
--EXPECTF--
Test: Spawn first, then Fiber
Coroutine spawned, now creating Fiber
Starting Fiber
Coroutine started
Inside Fiber - this should conflict with active scheduler
Coroutine resumed
Fiber suspended with: fiber suspended
Resuming Fiber
Fiber resumed
Fiber completed with: fiber done
Getting coroutine result
Coroutine completed with: coroutine result
Test completed