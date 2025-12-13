--TEST--
Multiple fibers GC during start (Revolt driver scenario)
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Multiple fibers GC on start\n";

class Driver {
    private $loopFiber;
    private $callbackFiber;

    public function __construct() {
        echo "Driver created\n";
        // Simulate Revolt's loop fiber and callback fiber
        $this->loopFiber = new Fiber(function() {
            echo "Loop fiber\n";
        });

        $this->callbackFiber = new Fiber(function() {
            echo "Callback fiber\n";
        });
    }

    public function __destruct() {
        echo "Driver destroyed\n";
    }
}

$c = spawn(function() {
    // Create temporary driver (like Revolt's GC protection driver)
    $tempDriver = new Driver();

    // Create actual driver
    $actualDriver = new Driver();

    // Replace temp with actual (simulates setDriver)
    $tempDriver = null;

    // Now create and start a fiber (simulates run())
    // This should trigger GC which destroys the temp driver
    $runFiber = new Fiber(function() {
        echo "Run fiber started\n";
    });

    echo "About to start run fiber\n";
    $runFiber->start();
    echo "Run fiber completed\n";

    return "done";
});

await($c);
echo "Test completed\n";
?>
--EXPECT--
Test: Multiple fibers GC on start
Driver created
Driver created
About to start run fiber
Driver destroyed
Run fiber started
Run fiber completed
Test completed
