--TEST--
Deadlock occurs when a coroutine continues execution after being cancelled.
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

echo "start\n";

$coroutine1 = null;
$coroutine2 = null;

$coroutine1 = spawn(function() use (&$coroutine2) {
    echo "coroutine1 running\n";
    suspend(); // Yield to allow the coroutine to
    try {
        await($coroutine2);
    } catch (Throwable $e) {
        echo "Caught exception: " . $e->getMessage() . "\n";
    }
    echo "coroutine1 finished\n";
});

$coroutine2 = spawn(function() use ($coroutine1) {
    echo "coroutine2 running\n";
    suspend(); // Yield to allow the coroutine to start
    try {
        await($coroutine1);
    } catch (Throwable $e) {
        echo "Caught exception: " . $e->getMessage() . "\n";
    }
    echo "coroutine2 finished\n";
});

echo "end\n";
?>
--EXPECTF--
start
end
coroutine1 running
coroutine2 running

Warning: no active coroutines, deadlock detected. Coroutines in waiting: %d in Unknown on line %d

Warning: the coroutine was suspended in file: %s, line: %d will be canceled in Unknown on line %d

Warning: the coroutine was suspended in file: %s, line: %d will be canceled in Unknown on line %d
Caught exception: Deadlock detected
coroutine1 finished
coroutine2 finished