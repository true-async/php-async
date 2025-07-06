--TEST--
awaitAll() - With concurrent generator using suspend() in body
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\suspend;

function concurrentGenerator($values) {
    foreach ($values as $value) {
        // Suspend before yielding each coroutine
        suspend();
        yield spawn(fn() => $value);
    }
}

echo "start\n";

$values = ["first", "second", "third"];
$generator = concurrentGenerator($values);

$results = awaitAll($generator);

echo "Results: " . implode(", ", $results) . "\n";
echo "Count: " . count($results) . "\n";
echo "end\n";

?>
--EXPECT--
start
Results: first, second, third
Count: 3
end