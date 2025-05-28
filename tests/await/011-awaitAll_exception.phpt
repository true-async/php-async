--TEST--
awaitAll() - one coroutine throws exception
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
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
    spawn(function() {
        delay(30);
        return "third";
    }),
];

try {
    $results = awaitAll($coroutines);
    var_dump($results);
} catch (RuntimeException $e) {
    echo "caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";
?>
--EXPECT--
start
caught exception: test exception
end