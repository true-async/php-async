--TEST--
Async\await(): same awaitable and cancellation object clears the cancellation slot
--FILE--
<?php

use function Async\await;
use function Async\spawn;
use Async\FutureState;
use Async\Future;

// Covers async.c PHP_FUNCTION(Async_await) L306-307:
// when awaitable_event == cancellation_event, cancellation_event is
// cleared so the two don't race.

$coroutine = spawn(function() {
    $state = new FutureState();
    $f = new Future($state);
    $state->complete("value");
    // Pass the same future as both the target and cancellation.
    var_dump(await($f, $f));
});

$result = \Async\await($coroutine);

?>
--EXPECT--
string(5) "value"
