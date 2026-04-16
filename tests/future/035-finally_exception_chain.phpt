--TEST--
Future::finally() - handler throwing on a rejected parent chains the parent as previous
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;

// Covers future.c ~L1449: zend_exception_set_previous() — when the parent
// future is already rejected and the finally handler throws, the thrown
// exception's ->previous is set to the original parent exception.

$coroutine = spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $result = $future->finally(function() {
        throw new \LogicException("finally boom");
    });

    $state->error(new \RuntimeException("parent boom"));

    try {
        await($result);
    } catch (\LogicException $e) {
        echo "top: ", get_class($e), " - ", $e->getMessage(), "\n";
        $prev = $e->getPrevious();
        echo "prev: ", get_class($prev), " - ", $prev->getMessage(), "\n";
    }
});

await($coroutine);

?>
--EXPECT--
top: LogicException - finally boom
prev: RuntimeException - parent boom
