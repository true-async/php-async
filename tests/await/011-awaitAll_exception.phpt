--TEST--
awaitAll() - one coroutine throws exception
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

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