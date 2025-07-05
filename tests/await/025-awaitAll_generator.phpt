--TEST--
awaitAll() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\await;
use function Async\delay;

function createCoroutines() {
    yield spawn(function() {
        delay(10);
        return "first";
    });
    
    yield spawn(function() {
        delay(20);
        return "second";
    });
    
    yield spawn(function() {
        delay(30);
        return "third";
    });
}

echo "start\n";

$generator = createCoroutines();
$results = awaitAll($generator);

$countOfResults = count($results) == 3 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: OK
end