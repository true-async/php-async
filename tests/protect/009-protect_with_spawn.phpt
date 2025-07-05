--TEST--
Async\protect: protect inside spawn coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\protect;
use function Async\await;

echo "start\n";

$coroutine = spawn(function() {
    echo "spawn start\n";
    
    protect(function() {
        echo "protected in spawn\n";
        
        // Do some work
        $result = 1 + 2;
        echo "result: $result\n";
    });
    
    echo "spawn end\n";
    return "spawn result";
});

$result = await($coroutine);
echo "final result: $result\n";

echo "end\n";

?>
--EXPECT--
start
spawn start
protected in spawn
result: 3
spawn end
final result: spawn result
end