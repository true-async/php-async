--TEST--
await_all_or_fail() - with cancellation timeout
--FILE--
<?php

use function Async\spawn;
use function Async\await_all_or_fail;
use function Async\await;
use function Async\delay;
use function Async\timeout;

$coroutines = [
    spawn(function() {
        return "fast";
    }),
    
    spawn(function() {
        delay(10); // This will be cancelled
        return "slow";
    }),
    
    spawn(function() {
        delay(10); // This will also be cancelled
        return "very slow";
    })
];

echo "start\n";

try {
    $results = await_all_or_fail($coroutines, timeout(1));
    echo "Unexpected success\n";
} catch (Async\OperationCanceledException $e) {
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