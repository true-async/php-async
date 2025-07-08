--TEST--
Cancellation of coroutine during protected operation with exception handling
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\protect;

echo "start\n";

$already_protected = spawn(function() {
    echo "already protected started\n";
    
    try {
        protect(function() {
            echo "protection started\n";
            suspend();
            suspend();
            echo "protection completed\n";
        });
        echo "after protection block\n";
    } catch (\Async\CancellationException $e) {
        echo "caught cancellation in coroutine: " . $e->getMessage() . "\n";
        throw $e;
    }
    
    return "should_not_reach";
});

suspend(); // Enter protection

// Cancel while protected
$already_protected->cancel(new \Async\CancellationException("Cancel during protection"));

suspend(); // Still in protection
suspend(); // Protection completes, cancellation should execute

try {
    $result = $already_protected->getResult();
    echo "should not get result\n";
} catch (\Async\CancellationException $e) {
    echo "protection cancellation: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
already protected started
protection started
protection completed
caught cancellation in coroutine: Cancel during protection
protection cancellation: Cancel during protection
end