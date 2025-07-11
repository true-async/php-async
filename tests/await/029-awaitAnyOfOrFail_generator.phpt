--TEST--
awaitAnyOfOrFail() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfOrFail;
use function Async\await;

function createCoroutines() {
    yield spawn(function() {
        return "first";
    });
    
    yield spawn(function() {
        return "second";
    });
    
    yield spawn(function() {
        return "third";
    });
    
    yield spawn(function() {
        return "fourth";
    });
}

echo "start\n";

$generator = createCoroutines();
$results = awaitAnyOfOrFail(2, $generator);

$countOfResults = count($results) >= 2 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: OK
end