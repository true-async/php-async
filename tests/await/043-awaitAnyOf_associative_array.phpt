--TEST--
awaitAnyOf() - with associative array
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOf;
use function Async\await;

$coroutines = [
    'slow' => spawn(function() {
        return "slow task";
    }),
    
    'fast' => spawn(function() {
        return "fast task";
    }),
    
    'medium' => spawn(function() {
        return "medium task";
    }),
    
    'very_slow' => spawn(function() {
        return "very slow task";
    })
];

echo "start\n";

$results = awaitAnyOf(2, $coroutines);

echo "Count: " . count($results) . "\n";
echo "Keys preserved: " . (count(array_intersect(array_keys($results), ['slow', 'fast', 'medium', 'very_slow'])) == count($results) ? "YES" : "NO") . "\n";

// The fastest should complete first
$keys = array_keys($results);
echo "First completed key: {$keys[0]}\n";
echo "First completed value: {$results[$keys[0]]}\n";

echo "end\n";

?>
--EXPECT--
start
Count: 2
Keys preserved: YES
First completed key: fast
First completed value: fast task
end