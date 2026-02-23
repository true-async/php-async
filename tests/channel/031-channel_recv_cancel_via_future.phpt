--TEST--
Channel: recv cancelled via arbitrary Future cancellation token
--FILE--
<?php

use Async\Channel;
use Async\AsyncCancellation;
use Async\FutureState;
use Async\Future;
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
        echo "Caught AsyncCancellation\n";
    }
    echo "Done\n";
});

spawn(function() use ($state) {
    delay(10);
    echo "Signalling cancel\n";
    $state->complete(null);
});

await($coroutine);

?>
--EXPECT--
Waiting for recv with future cancellation
Signalling cancel
Caught AsyncCancellation
Done
