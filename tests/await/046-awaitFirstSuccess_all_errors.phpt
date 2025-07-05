--TEST--
awaitFirstSuccess() - when all coroutines throw errors
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\await;
use function Async\delay;

$coroutines = [
    spawn(function() {
        delay(10);
        throw new RuntimeException("first error");
    }),
    
    spawn(function() {
        delay(20);
        throw new InvalidArgumentException("second error");
    }),
    
    spawn(function() {
        delay(30);
        throw new LogicException("third error");
    })
];

echo "start\n";

try {
    $result = awaitFirstSuccess($coroutines);
    echo "Unexpected success: " . print_r($result, true) . "\n";
} catch (Exception $e) {
    echo "Exception caught: " . get_class($e) . " - " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Exception caught: RuntimeException - first error
end