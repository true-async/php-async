--TEST--
awaitAnyOf() - edge cases with count parameter
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOf;
use function Async\await;
use function Async\delay;

$coroutines = [
    spawn(function() {
        delay(10);
        return "first";
    }),
    
    spawn(function() {
        delay(20);
        return "second";
    })
];

echo "start\n";

// Test requesting more than available
try {
    $results = awaitAnyOf(5, $coroutines);
    echo "Count when requesting more than available: " . count($results) . "\n";
} catch (Exception $e) {
    echo "Exception when requesting more: " . get_class($e) . "\n";
}

// Test requesting zero
try {
    $results = awaitAnyOf(0, $coroutines);
    echo "Count when requesting zero: " . count($results) . "\n";
} catch (Exception $e) {
    echo "Exception when requesting zero: " . get_class($e) . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Count when requesting more than available: 2
Count when requesting zero: 0
end