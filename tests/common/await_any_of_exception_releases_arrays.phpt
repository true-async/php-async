--TEST--
Async\await_any_of(): exception from await_futures releases results/errors arrays
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_any_of;

// Covers async.c PHP_FUNCTION(Async_await_any_of) L637-640: exception path
// releases the results and errors arrays and re-throws. Triggered by
// passing a non-iterable futures argument (TypeError inside async_await_futures).

$coroutine = spawn(function() {
    try {
        await_any_of(1, 42); // 42 is not iterable
        echo "no-throw\n";
    } catch (\Throwable $e) {
        echo "caught: ", get_class($e), "\n";
    }
});

await($coroutine);

?>
--EXPECT--
caught: Async\AsyncException
