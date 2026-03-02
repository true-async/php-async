--TEST--
Fiber remains suspended after manager fiber terminates (event loop scenario)
--FILE--
<?php

echo "Test: Suspended worker fiber after manager terminates\n";

// Worker fiber with infinite loop (like Revolt's callbackFiber)
$workerFiber = new Fiber(function() {
    echo "Worker: started\n";
    do {
        echo "Worker: processing\n";
        Fiber::suspend(); // Wait for next work
        echo "Worker: resumed\n";
    } while (true); // Infinite loop
});

// Manager fiber that controls worker (like Revolt's loop fiber)
$managerFiber = new Fiber(function() use ($workerFiber) {
    echo "Manager: starting worker\n";
    $workerFiber->start();

    echo "Manager: resuming worker once\n";
    $workerFiber->resume();

    echo "Manager: no more work, terminating\n";
    // Manager terminates here, leaving worker suspended
    return "done";
});

echo "Starting manager\n";
$managerFiber->start();
$result = $managerFiber->getReturn();

echo "Manager terminated with: $result\n";
echo "Worker is: " . ($workerFiber->isSuspended() ? "suspended" : "other") . "\n";

echo "Test completed\n";
?>
--EXPECT--
Test: Suspended worker fiber after manager terminates
Starting manager
Manager: starting worker
Worker: started
Worker: processing
Manager: resuming worker once
Worker: resumed
Worker: processing
Manager: no more work, terminating
Manager terminated with: done
Worker is: suspended
Test completed
