--TEST--
await_any_of() - basic usage with mixed success and error
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_of;
use function Async\suspend;

echo "start\n";

$coroutines = [
    spawn(function() {
        return "first";
    }),
    spawn(function() {
        throw new RuntimeException("test exception");
    }),
    spawn(function() {
        return "third";
    }),
    spawn(function() {
        return "fourth";
    }),
];

$result = await_any_of(2, $coroutines);

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