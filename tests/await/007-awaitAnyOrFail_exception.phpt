--TEST--
await_any_or_fail() - coroutine throws exception
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_or_fail;
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
    $result = await_any_or_fail($coroutines);
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