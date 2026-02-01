--TEST--
Future::finally() - exception overrides original result
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
        echo "Finally throwing\n";
        throw new Exception("Finally error");
    });

    $state->complete(42);

    try {
        await($result);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
});

await($coroutine);

?>
--EXPECT--
Finally throwing
Error: Finally error
