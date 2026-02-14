--TEST--
Cancel fiber's coroutine from nested spawn
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;
use Async\AsyncCancellation;

$outer = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Fiber running\n";
        Fiber::suspend();
        echo "This should not print\n";
        return "done";
    });

    $fiber->start();
    $fiberCoro = $fiber->getCoroutine();

    // Nested coroutine cancels fiber's coroutine
    $inner = spawn(function() use ($fiberCoro) {
        echo "Inner: cancelling fiber coroutine\n";
        $fiberCoro->cancel(new AsyncCancellation("nested cancel"));
    });

    await($inner);
    suspend();

    // Try to use fiber
    try {
        $fiber->resume();
    } catch (Throwable $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    return "outer done";
});

$result = await($outer);
echo "Result: {$result}\n";
echo "OK\n";
?>
--EXPECTF--
Fiber running
Inner: cancelling fiber coroutine
%a
OK
