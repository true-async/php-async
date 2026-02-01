--TEST--
Future::map() with catch() - error recovery in chain
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $result = $future
        ->map(function($x) {
            echo "Map: $x\n";
            throw new Exception("Map failed");
        })
        ->catch(function($e) {
            echo "Caught: " . $e->getMessage() . "\n";
            return 999;
        });

    $state->complete(42);

    return await($result);
});

echo "Result: " . await($coroutine) . "\n";

?>
--EXPECT--
Map: 42
Caught: Map failed
Result: 999
