--TEST--
Coroutine: late await() after coroutine finished with exception delivers it without double-throw (#139)
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

spawn(function() {
    $c = spawn(function() {
        throw new \RuntimeException('deferred boom');
    });

    // Let $c run and finish (throw) before we await it. At this point no
    // awaiter is attached to $c.
    delay(10);

    try {
        await($c);
        echo "ERROR: await returned without an exception\n";
    } catch (\RuntimeException $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }

    // Awaiting the same finished-with-exception coroutine again must keep
    // delivering the stored exception, not crash or return.
    try {
        await($c);
        echo "ERROR: second await returned without an exception\n";
    } catch (\RuntimeException $e) {
        echo "caught again: " . $e->getMessage() . "\n";
    }
});

echo "done\n";
?>
--EXPECT--
done
caught: deferred boom
caught again: deferred boom
