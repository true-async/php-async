--TEST--
awaitAny() - coroutine throws exception
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
        throw new RuntimeException("test exception");
    }),
];

try {
    $result = awaitAny($coroutines);
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