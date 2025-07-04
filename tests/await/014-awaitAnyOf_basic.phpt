--TEST--
awaitAnyOf() - basic usage with count parameter
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOf;
use function Async\delay;

echo "start\n";

$coroutines = [
    spawn(function() {
        delay(80);
        return "first";
    }),
    spawn(function() {
        delay(20);
        return "second";
    }),
    spawn(function() {
        delay(60);
        return "third";
    }),
    spawn(function() {
        delay(25);
        return "fourth";
    }),
];

$results = awaitAnyOf(2, $coroutines);

$countOfResults = count($results) >= 2 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

echo "end\n";
?>
--EXPECT--
start
Count of results: OK
end