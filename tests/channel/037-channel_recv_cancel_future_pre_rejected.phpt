--TEST--
Channel: recv throws AsyncCancellation with original exception as $previous when Future cancellation token is already rejected
--FILE--
<?php

use Async\Channel;
use Async\FutureState;
use Async\Future;
use Async\AsyncCancellation;
use function Async\spawn;
use function Async\await;

$ch = new Channel(0);

$state = new FutureState();
$state->error(new \RuntimeException("Already cancelled"));
$cancel = new Future($state);

$coroutine = spawn(function() use ($ch, $cancel) {
    try {
        $ch->recv($cancel);
        echo "Should not reach here\n";
    } catch (AsyncCancellation $e) {
        $prev = $e->getPrevious();
        echo "Caught: " . $e->getMessage() . "\n";
        echo "Previous: " . ($prev ? get_class($prev) . ': ' . $prev->getMessage() : 'none') . "\n";
    }
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Caught: Operation has been cancelled
Previous: RuntimeException: Already cancelled
Done
