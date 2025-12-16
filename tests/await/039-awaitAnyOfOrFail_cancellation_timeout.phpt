--TEST--
await_any_of_or_fail() - with cancellation timeout
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_of_or_fail;
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
    }),
    
    spawn(function() {
        delay(10); // Will be cancelled
        return "extremely slow";
    })
];

echo "start\n";

try {
    $results = await_any_of_or_fail(2, $coroutines, timeout(1));
    echo "Unexpected success\n";
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