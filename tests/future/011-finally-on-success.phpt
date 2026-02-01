--TEST--
Future::finally() - executes on successful completion
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $result = $future->finally(function() {
        echo "Finally executed\n";
    });

    $state->complete(42);

    return await($result);
});

echo "Result: " . await($coroutine) . "\n";

?>
--EXPECT--
Finally executed
Result: 42
