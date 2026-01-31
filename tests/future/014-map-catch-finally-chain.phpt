--TEST--
Future - full transformation chain with map, catch, finally
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
            return $x * 2;
        })
        ->catch(function($e) {
            echo "Catch should not be called\n";
            return 0;
        })
        ->finally(function() {
            echo "Finally executed\n";
        });

    $state->complete(21);

    return await($result);
});

echo "Result: " . await($coroutine) . "\n";

?>
--EXPECT--
Map: 21
Finally executed
Result: 42
