--TEST--
awaitAnyOrFail() - basic usage with multiple coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOrFail;
use function Async\delay;
use function Async\suspend;

echo "start\n";

$coroutines = [
    spawn(function() {
        suspend();
        return "first";
    }),
    spawn(function() {
        return "second";
    }),
    spawn(function() {
        suspend();
        return "third";
    }),
];

$result = awaitAnyOrFail($coroutines);
echo "first completed: $result\n";

echo "end\n";
?>
--EXPECT--
start
first completed: second
end