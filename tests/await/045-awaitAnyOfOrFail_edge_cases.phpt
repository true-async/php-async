--TEST--
awaitAnyOfOrFail() - edge cases with count parameter
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfOrFail;
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
    $results = awaitAnyOfOrFail(5, $coroutines);
    echo "Count when requesting more than available: " . count($results) . "\n";
} catch (Exception $e) {
    echo "Exception when requesting more: " . get_class($e) . "\n";
}

// Test requesting zero
try {
    $results = awaitAnyOfOrFail(0, $coroutines);
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