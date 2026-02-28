--TEST--
await() with pre-completed Future as cancel token throws AsyncCancellation immediately
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use Async\AsyncCancellation;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$state = new FutureState();
$state->complete(null);
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
    } catch (AsyncCancellation $e) {
        echo "Caught: " . $e->getMessage() . "\n";
        echo "Previous: " . ($e->getPrevious() ? 'yes' : 'none') . "\n";
    }
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Caught: Operation has been cancelled
Previous: none
Done
