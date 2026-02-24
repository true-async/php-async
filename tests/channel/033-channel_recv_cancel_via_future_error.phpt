--TEST--
Channel: recv cancelled via Future that resolves with an exception
--FILE--
<?php

use Async\Channel;
use Async\FutureState;
use Async\Future;
use Async\AsyncCancellation;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$ch = new Channel(0);
$state = new FutureState();
$cancel = new Future($state);

$coroutine = spawn(function() use ($ch, $cancel) {
    try {
        echo "Waiting for recv with future cancellation\n";
        $ch->recv($cancel);
        echo "Should not reach here\n";
    } catch (AsyncCancellation $e) {
        $prev = $e->getPrevious();
        echo "Caught: " . $e->getMessage() . "\n";
        echo "Previous: " . ($prev ? get_class($prev) . ': ' . $prev->getMessage() : 'none') . "\n";
    }
    echo "Done\n";
});

spawn(function() use ($state) {
    echo "Signalling cancel with error\n";
    $state->error(new \RuntimeException("Custom cancel reason"));
});

await($coroutine);

?>
--EXPECT--
Waiting for recv with future cancellation
Signalling cancel with error
Caught: Operation has been cancelled
Previous: RuntimeException: Custom cancel reason
Done
