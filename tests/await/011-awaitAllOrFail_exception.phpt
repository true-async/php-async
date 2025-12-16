--TEST--
await_all_or_fail() - one coroutine throws exception
--FILE--
<?php

use function Async\spawn;
use function Async\await_all_or_fail;

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
    $results = await_all_or_fail($coroutines);
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