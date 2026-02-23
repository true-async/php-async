--TEST--
Channel: send cancelled via arbitrary Future cancellation token
--FILE--
<?php

use Async\Channel;
use Async\AsyncCancellation;
use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await;
use function Async\delay;

// Fill rendezvous slot so send() will block
$ch = new Channel(0);
$ch->sendAsync("fill");

$state = new FutureState();
$cancel = new Future($state);

$coroutine = spawn(function() use ($ch, $cancel) {
    try {
        echo "Waiting for send with future cancellation\n";
        $ch->send("blocked", $cancel);
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
Waiting for send with future cancellation
Signalling cancel
Caught AsyncCancellation
Done
