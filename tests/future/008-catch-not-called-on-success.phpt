--TEST--
Future::catch() - not called on successful result
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $caught = $future->catch(function($e) {
        echo "This should not be called\n";
        return 0;
    });

    $state->complete(42);

    return await($caught);
});

echo "Result: " . await($coroutine) . "\n";

?>
--EXPECT--
Result: 42
