--TEST--
awaitAllOrFail() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllOrFail;
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
}

echo "start\n";

$generator = createCoroutines();
$results = awaitAllOrFail($generator);

$countOfResults = count($results) == 3 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: OK
end