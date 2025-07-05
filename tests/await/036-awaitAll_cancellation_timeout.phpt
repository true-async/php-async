--TEST--
awaitAll() - with cancellation timeout
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\await;
use function Async\delay;
use function Async\timeout;

$coroutines = [
    spawn(function() {
        delay(10);
        return "fast";
    }),
    
    spawn(function() {
        delay(100); // This will be cancelled
        return "slow";
    }),
    
    spawn(function() {
        delay(200); // This will also be cancelled
        return "very slow";
    })
];

echo "start\n";

try {
    $results = awaitAll($coroutines, timeout(50));
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