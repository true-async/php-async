--TEST--
awaitFirstSuccess() - when all coroutines throw errors
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\await;

$coroutines = [
    spawn(function() {
        throw new RuntimeException("first error");
    }),
    
    spawn(function() {
        throw new InvalidArgumentException("second error");
    }),
    
    spawn(function() {
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