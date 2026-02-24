--TEST--
await() with pre-rejected Future cancel token containing CancellationException wraps in OperationCanceledException
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use Async\AsyncCancellation;
use Async\OperationCanceledException;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$state = new FutureState();
$state->error(new AsyncCancellation("Custom cancellation reason"));
$cancel = new Future($state);

$coroutine = spawn(function() use ($cancel) {
    $worker = spawn(function() {
        suspend();
        suspend();
        return "result";
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
Previous: Async\AsyncCancellation: Custom cancellation reason
Done
