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
$result = awaitAll($coroutines, null, fillNull:true);

echo "Count of results: " . count($result[0]) . "\n";
echo "Count of errors: " . count($result[1]) . "\n";
echo "Result 0: " . ($result[0][0] ?? "null") . "\n";
echo "Result 1: " . ($result[0][1] ?? "null") . "\n";
echo "Result 2: " . ($result[0][2] ?? "null") . "\n";
echo "Error message: " . $result[1][1]->getMessage() . "\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: 3
Count of errors: 1
Result 0: success
Result 1: null
Result 2: another success
Error message: error
end