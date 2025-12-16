--TEST--
Cancel fiber's coroutine while fiber is suspended
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;
use Async\CancellationError;

$c = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Fiber: before suspend\n";
        Fiber::suspend();
        echo "Fiber: after suspend\n";
        return "result";
    });

    $fiber->start();

    // Fiber is suspended, cancel its coroutine
    $coro = $fiber->getCoroutine();
    echo "Cancelling coroutine\n";
    $coro->cancel(new CancellationError("test"));

    // Give scheduler a chance
    suspend();

    // Try resume
    try {
        $fiber->resume();
    } catch (Throwable $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
});

await($c);
echo "OK\n";
?>
--EXPECTF--
Fiber: before suspend
Cancelling coroutine
Caught: Cannot resume a fiber that is not suspended
OK
