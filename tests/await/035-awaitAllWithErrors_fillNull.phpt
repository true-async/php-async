--TEST--
awaitAllWithErrors() - with fillNull parameter
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllWithErrors;
use function Async\await;
use function Async\delay;

$coroutines = [
    spawn(function() {
        delay(10);
        return "success";
    }),
    
    spawn(function() {
        delay(20);
        throw new RuntimeException("error");
    }),
    
    spawn(function() {
        delay(30);
        return "another success";
    })
];

echo "start\n";

// Test with fillNull = true
$result = awaitAllWithErrors($coroutines, null, true);

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