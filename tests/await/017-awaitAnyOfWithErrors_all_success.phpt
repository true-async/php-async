--TEST--
awaitAnyOfWithErrors() - all coroutines succeed
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfWithErrors;
use function Async\delay;

echo "start\n";

$coroutines = [
    spawn(function() {
        delay(50);
        return "first";
    }),
    spawn(function() {
        delay(20);
        return "second";
    }),
    spawn(function() {
        delay(30);
        return "third";
    }),
];

$result = awaitAnyOfWithErrors(2, $coroutines);

$countOfResults = count($result[0]) >= 2 ? "OK" : "FALSE: ".count($result[0]);
$countOfErrors = count($result[1]) == 0 ? "OK" : "FALSE: ".count($result[1]);

echo "Count of results: $countOfResults\n";
echo "Count of errors: $countOfErrors\n";

echo "end\n";
?>
--EXPECTF--
start
Count of results: OK
Count of errors: OK
end