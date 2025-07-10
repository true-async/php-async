--TEST--
awaitAny() - with cancellation timeout
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
use function Async\await;
use function Async\delay;
use function Async\timeout;

$coroutines = [
    spawn(function() {
        delay(10); // Will be cancelled
        return "slow";
    }),
    
    spawn(function() {
        delay(10); // Will be cancelled
        return "very slow";
    })
];

echo "start\n";

try {
    $result = awaitAny($coroutines, timeout(1));
    echo "Unexpected success: $result\n";
} catch (Async\TimeoutException $e) {
    echo "Timeout caught as expected\n";
} catch (Exception $e) {
    echo "Exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Timeout caught as expected
end