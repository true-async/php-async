--TEST--
Fiber GC during another fiber start (Revolt scenario)
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Fiber GC during start\n";

class FiberHolder {
    private $fiber;

    public function __construct() {
        echo "FiberHolder created\n";
        $this->fiber = new Fiber(function() {
            echo "Temp fiber\n";
        });
    }

    public function __destruct() {
        echo "FiberHolder destroyed\n";
    }
}

$c = spawn(function() {
    // Create a temporary fiber holder (like Revolt's temporary driver)
    $temp = new FiberHolder();

    // Create the main fiber (like Revolt's actual driver)
    $mainFiber = new Fiber(function() {
        echo "Main fiber started\n";
    });

    // Unset temp to make it eligible for GC
    unset($temp);

    // Starting main fiber might trigger GC, which destroys temp
    // This simulates what happens in Revolt when EventLoop::setDriver creates
    // a temporary driver, then the real driver, and GC happens on fiber->start()
    $mainFiber->start();

    return "done";
});

await($c);
echo "Test completed\n";
?>
--EXPECT--
Test: Fiber GC during start
FiberHolder created
FiberHolder destroyed
Main fiber started
Test completed
