--TEST--
await() with Future cancel token completed during suspension throws AsyncCancellation
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use Async\AsyncCancellation;
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

    // Complete the cancel token from another coroutine
    spawn(function() use ($state) {
        $state->complete(null);
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
