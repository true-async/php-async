--TEST--
Coroutine with deep recursion and stack limits
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "start\n";

$deep_recursion_coroutine = spawn(function() {
    echo "deep recursion coroutine started\n";
    
    function deepRecursionTest($depth, $maxDepth = 100) {
        if ($depth >= $maxDepth) {
            echo "reached max depth: $depth\n";
            return $depth;
        }
        
        if ($depth % 20 === 0) {
            suspend(); // Suspend periodically
        }
        
        return deepRecursionTest($depth + 1, $maxDepth);
    }
    
    $result = deepRecursionTest(0);
    return "recursion_result_$result";
});

$result = $deep_recursion_coroutine->getResult();
echo "deep recursion result: $result\n";

echo "end\n";

?>
--EXPECTF--
start
deep recursion coroutine started
reached max depth: 100
deep recursion result: recursion_result_100
end