--TEST--
Channel: send cancelled via Future that resolves with an exception
--FILE--
<?php

use Async\Channel;
use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await;
use function Async\delay;

// Fill rendezvous slot so send() will block
$ch = new Channel(0);
$ch->sendAsync("fill");

$state = new FutureState();
$state->ignore();
$cancel = new Future($state);

$coroutine = spawn(function() use ($ch, $cancel) {
    try {
        echo "Waiting for send with future cancellation\n";
        $ch->send("blocked", $cancel);
        echo "Should not reach here\n";
    } catch (\RuntimeException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
    echo "Done\n";
});

spawn(function() use ($state) {
    delay(10);
    echo "Signalling cancel with error\n";
    $state->error(new \RuntimeException("Custom cancel reason"));
});

await($coroutine);

?>
--EXPECT--
Waiting for send with future cancellation
Signalling cancel with error
Caught: Custom cancel reason
Done
