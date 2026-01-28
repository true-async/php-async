--TEST--
await_any_of_or_fail() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_of_or_fail;
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
$results = await_any_of_or_fail(2, $generator);

$countOfResults = count($results) >= 2 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: OK
end