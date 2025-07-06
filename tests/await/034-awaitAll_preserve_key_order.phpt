--TEST--
awaitAll() - with fillNull parameter
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\await;
use function Async\suspend;

$coroutines = [
    spawn(function() {
        suspend(); // Simulate a delay
        return "success1";
    }),
    
    spawn(function() {
        return "success2";
    }),
    
    spawn(function() {
        return "success3";
    })
];

echo "start\n";

$results = awaitAll($coroutines, null, false);
$expectedResults = [
    "success1",
    "success2",
    "success3"
];

// Check that all values from the expected array exist, regardless of order.
$missingResults = array_diff($expectedResults, $results);

if (!empty($missingResults)) {
    echo "Missing results: " . implode(", ", $missingResults) . "\n";
} else {
    echo "All expected results found\n";
}

echo "end\n";

?>
--EXPECT--
start
All expected results found
end