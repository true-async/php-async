--TEST--
Cancel fiber's coroutine via getCoroutine()->cancel()
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\AsyncCancellation;

$c = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Fiber started\n";
        Fiber::suspend("suspended");
        echo "Fiber resumed\n";
        return "done";
    });

    $fiber->start();
    echo "Fiber suspended\n";

    // Get fiber's coroutine and cancel it
    $coro = $fiber->getCoroutine();
    $coro->cancel(new AsyncCancellation("cancelled"));
    echo "Coroutine cancelled\n";

    // Try to resume fiber
    try {
        $fiber->resume();
        echo "Fiber completed\n";
    } catch (AsyncCancellation $e) {
        echo "AsyncCancellation: " . $e->getMessage() . "\n";
    } catch (FiberError $e) {
        echo "FiberError: " . $e->getMessage() . "\n";
    }
});

await($c);
echo "OK\n";
?>
--EXPECTF--
Fiber started
Fiber suspended
Coroutine cancelled
%a
OK
