--TEST--
awaitAny() - basic usage with multiple coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
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
        delay(100);
        return "third";
    }),
];

$result = awaitAny($coroutines);
echo "first completed: $result\n";

echo "end\n";
?>
--EXPECT--
start
first completed: second
end