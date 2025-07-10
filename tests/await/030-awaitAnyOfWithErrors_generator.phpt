--TEST--
awaitAnyOfWithErrors() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfWithErrors;
use function Async\await;
use function Async\delay;

function createCoroutines() {
    yield spawn(function() {
        return "first";
    });
    
    yield spawn(function() {
        throw new RuntimeException("error");
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
$result = awaitAnyOfWithErrors(2, $generator);

$countOfResults = count($result[0]) >= 2 ? "OK" : "FALSE: ".count($result[0]);
$countOfErrors = count($result[1]) == 1 ? "OK" : "FALSE: ".count($result[1]);

echo "Count of results: $countOfResults\n";
echo "Count of errors: $countOfErrors\n";

echo "end\n";

?>
--EXPECT--
start
Count of results: OK
Count of errors: OK
end