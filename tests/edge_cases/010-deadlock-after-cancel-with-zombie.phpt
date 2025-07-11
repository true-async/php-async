--TEST--
Deadlock - Deadlock is an operation after coroutines are cancelled, when they are already zombies.
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;
use Async\Scope;

echo "start\n";

$scope = new Scope();

$coroutine1 = null;
$coroutine2 = null;

$coroutine1 = $scope->spawn(function() use (&$coroutine2) {
    echo "coroutine1 running\n";
    suspend();

    try {
        await($coroutine2);
    } catch (Throwable $e) {
        echo "Caught exception: " . $e->getMessage() . "\n";
    }

    suspend();

    echo "coroutine1 finished\n";
});

$coroutine2 = $scope->spawn(function() use ($coroutine1) {
    echo "coroutine2 running\n";
    suspend();
    try {
        await($coroutine1);
    } catch (Throwable $e) {
        echo "Caught exception: " . $e->getMessage() . "\n";
    }

    suspend();

    echo "coroutine2 finished\n";
});

suspend(); // Suspend the main coroutine to allow the others to run
$scope->dispose(); // This will cancel the coroutines, making them zombies

echo "end\n";
?>
--EXPECTF--
start
coroutine1 running
coroutine2 running
end

Warning: no active coroutines, deadlock detected. Coroutines in waiting: 2 in %s on line %d

Warning: the coroutine was suspended in file: %s, line: %d will be canceled in %s on line %d

Warning: the coroutine was suspended in file: %s, line: %d will be canceled in %s on line %d
Caught exception: Deadlock detected
coroutine1 finished
coroutine2 finished