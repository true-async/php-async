--TEST--
Deadlock - The coroutine not only continues execution but also performs a suspend.
--INI--
async.debug_deadlock=0
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

    suspend();

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

    suspend();

    echo "coroutine2 finished\n";
});

echo "end\n";
?>
--EXPECTF--
start
end
coroutine1 running
coroutine2 running
Caught exception: Deadlock detected
Caught exception: Deadlock detected
coroutine1 finished
coroutine2 finished

Fatal error: Uncaught Async\DeadlockError: Deadlock detected: no active coroutines, 2 coroutines in waiting in [no active file]:0
Stack trace:
#0 {main}
  thrown in [no active file] on line 0