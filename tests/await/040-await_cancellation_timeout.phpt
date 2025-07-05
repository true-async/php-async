--TEST--
await() - with cancellation timeout
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;
use function Async\timeout;

$coroutine = spawn(function() {
    delay(100); // Will be cancelled
    return "success";
});

echo "start\n";

try {
    $result = await($coroutine, timeout(50));
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