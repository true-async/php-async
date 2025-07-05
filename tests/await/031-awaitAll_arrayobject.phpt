--TEST--
awaitAll() - with ArrayObject
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\await;
use function Async\delay;

$arrayObject = new ArrayObject([
    spawn(function() {
        delay(10);
        return "first";
    }),
    
    spawn(function() {
        delay(20);
        return "second";
    }),
    
    spawn(function() {
        delay(30);
        return "third";
    })
]);

echo "start\n";

$results = awaitAll($arrayObject);

echo "Count: " . count($results) . "\n";
echo "Result 0: {$results[0]}\n";
echo "Result 1: {$results[1]}\n";
echo "Result 2: {$results[2]}\n";
echo "end\n";

?>
--EXPECT--
start
Count: 3
Result 0: first
Result 1: second
Result 2: third
end