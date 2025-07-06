--TEST--
awaitAllWithErrors() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllWithErrors;
use function Async\await;

function createCoroutines() {
    yield spawn(function() {
        return "success";
    });
    
    yield spawn(function() {
        throw new RuntimeException("error");
    });
    
    yield spawn(function() {
        return "another success";
    });
}

echo "start\n";

$generator = createCoroutines();
$result = awaitAllWithErrors($generator);

$countOfResults = count($result[0]) == 3 ? "OK" : "FALSE: ".count($result[0]);
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