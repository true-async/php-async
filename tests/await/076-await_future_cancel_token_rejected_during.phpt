--TEST--
await() with Future cancel token rejected during suspension wraps exception in OperationCanceledException
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use Async\OperationCanceledException;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$state = new FutureState();
$cancel = new Future($state);

$coroutine = spawn(function() use ($state, $cancel) {
    $worker = spawn(function() {
        suspend();
        suspend();
        return "result";
    });

    // Reject the cancel token from another coroutine
    spawn(function() use ($state) {
        $state->error(new \RuntimeException("Cancelled with error"));
    });

    try {
        $result = await($worker, $cancel);
        echo "Should not reach here\n";
    } catch (OperationCanceledException $e) {
        $prev = $e->getPrevious();
        echo "Caught: " . get_class($e) . ": " . $e->getMessage() . "\n";
        echo "Previous: " . ($prev ? get_class($prev) . ': ' . $prev->getMessage() : 'none') . "\n";
    }
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Caught: Async\OperationCanceledException: Operation has been cancelled
Previous: RuntimeException: Cancelled with error
Done
