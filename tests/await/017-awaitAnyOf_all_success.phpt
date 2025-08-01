--TEST--
awaitAnyOf() - all coroutines succeed
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOf;

echo "start\n";

$coroutines = [
    spawn(function() {
        return "first";
    }),
    spawn(function() {
        return "second";
    }),
    spawn(function() {
        return "third";
    }),
];

$result = awaitAnyOf(2, $coroutines);

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