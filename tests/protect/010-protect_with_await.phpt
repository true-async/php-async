--TEST--
Async\protect: protect with await operations
--FILE--
<?php

use function Async\spawn;
use function Async\protect;
use function Async\await;

echo "start\n";

$child = spawn(function() {
    echo "child coroutine\n";
    return "child result";
});

$main = spawn(function() use ($child) {
    echo "main start\n";
    
    protect(function() use ($child) {
        echo "protected block start\n";
        
        // Await inside protected block
        $result = await($child);
        echo "await result: $result\n";
        
        echo "protected block end\n";
    });
    
    echo "main end\n";
    return "main result";
});

$result = await($main);
echo "final result: $result\n";

echo "end\n";

?>
--EXPECT--
start
child coroutine
main start
protected block start
await result: child result
protected block end
main end
final result: main result
end