--TEST--
Future: cancel() rejects pending future; idempotent on already-completed; custom cancellation
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\FutureState;
use Async\Future;
use Async\AsyncCancellation;

// Covers future.c FUTURE_METHOD(cancel) — default-cancellation, custom-cancellation
// and already-completed short-circuit branches (lines 1223-1253).

$coroutine = spawn(function() {
    // 1. cancel() on a pending future with no argument → AsyncCancellation with default message
    $state = new FutureState();
    $future = new Future($state);
    $future->cancel();
    var_dump($future->isCompleted());
    var_dump($future->isCancelled());
    try {
        await($future);
    } catch (AsyncCancellation $e) {
        echo "msg1: ", $e->getMessage(), "\n";
    }

    // 2. cancel() with explicit cancellation object
    $state2 = new FutureState();
    $future2 = new Future($state2);
    $future2->cancel(new AsyncCancellation("custom reason"));
    try {
        await($future2);
    } catch (AsyncCancellation $e) {
        echo "msg2: ", $e->getMessage(), "\n";
    }

    // 3. cancel() on an already-completed future is a no-op
    $state3 = new FutureState();
    $future3 = new Future($state3);
    $state3->complete(42);
    $future3->cancel();
    var_dump(await($future3));
});

await($coroutine);

?>
--EXPECT--
bool(true)
bool(true)
msg1: Future has been cancelled
msg2: custom reason
int(42)
