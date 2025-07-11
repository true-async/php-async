--TEST--
awaitAllOrFail() - with associative array
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllOrFail;
use function Async\await;

$coroutines = [
    'task1' => spawn(function() {
        return "first";
    }),
    
    'task2' => spawn(function() {
        return "second";
    }),
    
    'task3' => spawn(function() {
        return "third";
    })
];

echo "start\n";

$results = awaitAllOrFail($coroutines);

echo "Count: " . count($results) . "\n";
echo "Keys preserved: " . (array_keys($results) === ['task1', 'task2', 'task3'] ? "YES" : "NO") . "\n";
echo "Result task1: {$results['task1']}\n";
echo "Result task2: {$results['task2']}\n";
echo "Result task3: {$results['task3']}\n";
echo "end\n";

?>
--EXPECT--
start
Count: 3
Keys preserved: YES
Result task1: first
Result task2: second
Result task3: third
end