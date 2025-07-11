--TEST--
awaitAnyOrFail() - coroutine throws exception
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOrFail;
use function Async\suspend;

echo "start\n";

$coroutines = [
    spawn(function() {
        suspend();
        return "first";
    }),
    spawn(function() {
        throw new RuntimeException("test exception");
    }),
];

try {
    $result = awaitAnyOrFail($coroutines);
    echo "result: $result\n";
} catch (RuntimeException $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";
?>
--EXPECT--
start
caught exception: test exception
end