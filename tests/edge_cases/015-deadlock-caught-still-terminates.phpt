--TEST--
Deadlock: catching the cancellation does not suppress the terminal DeadlockError
--INI--
async.debug_deadlock=0
--FILE--
<?php

// A deadlock cancels the parked coroutines with a catchable AsyncCancellation, so the recv()
// below can be caught and the script keeps running. The deadlock itself is terminal, though:
// once the body finishes, the scheduler still raises DeadlockError to end the process.

$channel = new Async\Channel(1);

try {
    $channel->recv();
} catch (Async\AsyncCancellation $exception) {
    echo "caught: ", $exception::class, "\n";
}

echo "after catch\n";
?>
--EXPECTF--
caught: Async\AsyncCancellation
after catch

Fatal error: Uncaught Async\DeadlockError: Deadlock detected: no active coroutines, %d coroutines in waiting in %s:%d
Stack trace:
#0 %s(%d): Async\Channel->recv()
#1 {main}
  thrown in %s on line %d
