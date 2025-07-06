--TEST--
awaitAll() - with fillNull parameter
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\await;

$coroutines = [
    spawn(function() {
        return "success";
    }),
    
    spawn(function() {
        throw new RuntimeException("error");
    }),
    
    spawn(function() {
        return "another success";
    })
];

echo "start\n";

// Test with fillNull = true
try {
    $results = awaitAll($coroutines, null, true);
    echo "Count: " . count($results) . "\n";
    echo "Result 0: " . ($results[0] ?? "null") . "\n";
    echo "Result 1: " . ($results[1] ?? "null") . "\n";
    echo "Result 2: " . ($results[2] ?? "null") . "\n";
} catch (RuntimeException $e) {
    echo "Exception caught with fillNull=true: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Count: 3
Result 0: success
Result 1: null
Result 2: another success
end