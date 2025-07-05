--TEST--
awaitAnyOfWithErrors() - basic usage with mixed success and error
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfWithErrors;
use function Async\delay;

echo "start\n";

$coroutines = [
    spawn(function() {
        delay(80);
        return "first";
    }),
    spawn(function() {
        delay(20);
        throw new RuntimeException("test exception");
    }),
    spawn(function() {
        delay(20);
        return "third";
    }),
    spawn(function() {
        delay(35);
        return "fourth";
    }),
];

$result = awaitAnyOfWithErrors(2, $coroutines);

$countOfResults = count($result[0]) >= 2 ? "OK" : "FALSE: ".count($result[0]);
$countOfErrors = count($result[1]) == 1 ? "OK" : "FALSE: ".count($result[1]);

echo "Count of results: $countOfResults\n";
echo "Count of errors: $countOfErrors\n";

echo "end\n";
?>
--EXPECTF--
start
Count of results: OK
Count of errors: OK
end