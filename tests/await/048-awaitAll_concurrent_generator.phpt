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
        echo "Yielding item: $value\n";
        yield spawn(fn() => $value);
    }
}

echo "start\n";

$values = ["first", "second", "third"];
$generator = concurrentGenerator($values);

spawn(function() {
    // Simulate some processing
    for ($i = 1; $i <= 5; $i++) {
        echo "Processing item $i\n";
        suspend();
    }
});

$results = awaitAll($generator);

echo "Results: " . implode(", ", $results) . "\n";
echo "Count: " . count($results) . "\n";
echo "end\n";

?>
--EXPECT--
start
Processing item 1
Processing item 2
Yielding item: first
Processing item 3
Yielding item: second
Processing item 4
Yielding item: third
Processing item 5
Results: first, second, third
Count: 3
end