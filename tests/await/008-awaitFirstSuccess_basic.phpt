--TEST--
awaitFirstSuccess() - basic usage with mixed success and error
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\delay;

echo "start\n";

$coroutines = [
    spawn(function() {
        delay(10);
        throw new RuntimeException("first error");
    }),
    spawn(function() {
        delay(20);
        return "success";
    }),
    spawn(function() {
        delay(30);
        return "another success";
    }),
];

$result = awaitFirstSuccess($coroutines);
echo "Result: {$result[0]}\n";
echo "end\n";
?>
--EXPECTF--
start
Result: success
end