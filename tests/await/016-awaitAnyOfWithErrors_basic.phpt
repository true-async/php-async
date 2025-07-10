--TEST--
awaitAnyOfWithErrors() - basic usage with mixed success and error
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfWithErrors;
use function Async\suspend;

echo "start\n";

$coroutines = [
    spawn(function() {
        return "first";
    }),
    spawn(function() {
        suspend();
        throw new RuntimeException("test exception");
    }),
    spawn(function() {
        return "third";
    }),
    spawn(function() {
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