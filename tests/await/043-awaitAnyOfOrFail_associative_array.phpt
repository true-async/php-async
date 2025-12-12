--TEST--
awaitAnyOfOrFail() - with associative array
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOfOrFail;
use function Async\await;
use function Async\suspend;

$coroutines = [
    'slow' => spawn(function() {
        suspend();
        return "slow task";
    }),
    
    'fast' => spawn(function() {
        return "fast task";
    }),
    
    'medium' => spawn(function() {
        return "medium task";
    }),
    
    'very_slow' => spawn(function() {
        suspend();
        return "very slow task";
    })
];

echo "start\n";

$results = awaitAnyOfOrFail(3, $coroutines);

echo "Keys preserved: " . (count(array_intersect(array_keys($results), ['slow', 'fast', 'medium', 'very_slow'])) == count($results) ? "YES" : "NO") . "\n";

// The fastest should complete first
$keys = array_keys($results);
echo "First completed key: {$keys[0]}\n";
echo "First completed value: {$results[$keys[0]]}\n";

echo "end\n";

?>
--EXPECT--
start
Keys preserved: YES
First completed key: slow
First completed value: slow task
end