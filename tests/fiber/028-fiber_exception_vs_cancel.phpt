--TEST--
Fiber throws exception while coroutine is being cancelled
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\CancellationError;

$c = spawn(function() {
    $fiber = new Fiber(function() {
        Fiber::suspend();
        throw new Exception("fiber exception");
    });

    $fiber->start();
    $coro = $fiber->getCoroutine();

    // Cancel coroutine
    $coro->cancel(new CancellationError("cancel"));

    // Resume - what happens? Exception or CancellationError?
    try {
        $fiber->resume();
        echo "No exception\n";
    } catch (CancellationError $e) {
        echo "CancellationError: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
});

await($c);
echo "OK\n";
?>
--EXPECTF--
%a
OK
