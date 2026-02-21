--TEST--
Coroutine: Circular references between coroutines and finally handlers
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\current_coroutine;
use function Async\await;

echo "start\n";

$circular_finally_coroutine = spawn(function() {
    echo "circular finally coroutine started\n";
    
    $coroutine = \Async\current_coroutine();
    $data = new \stdClass();
    $data->coroutine = $coroutine;
    
    $coroutine->finally(function() use ($data) {
        echo "circular finally executed\n";
        $data->cleanup = "done";
        // $data holds reference to coroutine, creating cycle
    });
    
    suspend();
    return "circular_result";
});

await($circular_finally_coroutine);
$result = $circular_finally_coroutine->getResult();
echo "circular result: $result\n";

// Force garbage collection
gc_collect_cycles();
echo "gc after circular references\n";

echo "end\n";

?>
--EXPECTF--
start
circular finally coroutine started
circular finally executed
circular result: circular_result
gc after circular references
end