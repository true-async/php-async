--TEST--
await_first_success() - with cancellation timeout
--FILE--
<?php

use function Async\spawn;
use function Async\await_first_success;
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
    $result = await_first_success($coroutines, timeout(50));
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