--TEST--
Future: isCompleted()/isCancelled() across pending, completed, rejected and cancelled states
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;
use Async\AsyncCancellation;

// Covers future.c FUTURE_METHOD(isCompleted) and FUTURE_METHOD(isCancelled)
// across pending / completed / error / cancelled branches.

$coroutine = spawn(function() {
    // Pending
    $state = new FutureState();
    $future = new Future($state);
    var_dump($future->isCompleted());
    var_dump($future->isCancelled());

    // Completed successfully
    $state->complete(42);
    var_dump($future->isCompleted());
    var_dump($future->isCancelled());
    $future->ignore();

    // Rejected with a generic exception — completed but not cancelled
    $state2 = new FutureState();
    $future2 = new Future($state2);
    $state2->error(new \RuntimeException("boom"));
    var_dump($future2->isCompleted());
    var_dump($future2->isCancelled());
    $future2->ignore();

    // Rejected with AsyncCancellation — both flags true
    $state3 = new FutureState();
    $future3 = new Future($state3);
    $state3->error(new AsyncCancellation("stop"));
    var_dump($future3->isCompleted());
    var_dump($future3->isCancelled());
    $future3->ignore();
});

await($coroutine);

?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
