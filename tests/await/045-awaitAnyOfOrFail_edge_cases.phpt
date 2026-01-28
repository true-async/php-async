--TEST--
await_any_of_or_fail() - edge cases with count parameter
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_of_or_fail;
use function Async\await;

$coroutines = [
    spawn(function() {
        return "first";
    }),
    
    spawn(function() {
        return "second";
    })
];

echo "start\n";

// Test requesting more than available
try {
    $results = await_any_of_or_fail(5, $coroutines);
    echo "Count when requesting more than available: " . count($results) . "\n";
} catch (Exception $e) {
    echo "Exception when requesting more: " . get_class($e) . "\n";
}

// Test requesting zero
try {
    $results = await_any_of_or_fail(0, $coroutines);
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