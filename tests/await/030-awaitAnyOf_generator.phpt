--TEST--
await_any_of() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_of;
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
$result = await_any_of(2, $generator);

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