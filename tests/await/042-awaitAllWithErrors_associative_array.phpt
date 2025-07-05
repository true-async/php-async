--TEST--
awaitAllWithErrors() - with associative array
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllWithErrors;
use function Async\await;
use function Async\delay;

$coroutines = [
    'success1' => spawn(function() {
        delay(10);
        return "first success";
    }),
    
    'error1' => spawn(function() {
        delay(20);
        throw new RuntimeException("first error");
    }),
    
    'success2' => spawn(function() {
        delay(30);
        return "second success";
    })
];

echo "start\n";

$result = awaitAllWithErrors($coroutines);

echo "Count of results: " . count($result[0]) . "\n";
echo "Count of errors: " . count($result[1]) . "\n";
echo "Result keys: " . implode(', ', array_keys($result[0])) . "\n";
echo "Error keys: " . implode(', ', array_keys($result[1])) . "\n";
echo "Result success1: {$result[0]['success1']}\n";
echo "Result success2: {$result[0]['success2']}\n";
echo "Error error1: {$result[1]['error1']->getMessage()}\n";
echo "end\n";

?>
--EXPECT--
start
Count of results: 2
Count of errors: 1
Result keys: success1, success2
Error keys: error1
Result success1: first success
Result success2: second success
Error error1: first error
end