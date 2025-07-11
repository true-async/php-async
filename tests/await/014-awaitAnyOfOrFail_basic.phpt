--TEST--
awaitAnyOfOrFail() - basic usage with count parameter
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfOrFail;

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
    spawn(function() {
        return "fourth";
    }),
];

$results = awaitAnyOfOrFail(2, $coroutines);

$countOfResults = count($results) >= 2 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

echo "end\n";
?>
--EXPECT--
start
Count of results: OK
end