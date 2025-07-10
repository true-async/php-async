--TEST--
Coroutine with deep recursion and stack limits
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

echo "start\n";

$deep_recursion_coroutine = spawn(function() {
    echo "deep recursion coroutine started\n";
    
    function deepRecursionTest($depth, $maxDepth = 1000) {
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

$result = await($deep_recursion_coroutine);
echo "deep recursion result: $result\n";

echo "end\n";

?>
--EXPECTF--
start
deep recursion coroutine started
reached max depth: 1000
deep recursion result: recursion_result_1000
end