--TEST--
awaitFirstSuccess() - with cancellation timeout
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\await;
use function Async\delay;
use function Async\timeout;

$coroutines = [
    spawn(function() {
        throw new RuntimeException("fast error");
    }),
    
    spawn(function() {
        delay(100); // Will be cancelled before success
        return "success";
    })
];

echo "start\n";

try {
    $result = awaitFirstSuccess($coroutines, timeout(10));
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