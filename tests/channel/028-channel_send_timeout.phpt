--TEST--
Channel: send with cancellation token (timeout) throws OperationCanceledException
--FILE--
<?php

use Async\Channel;
use Async\OperationCanceledException;
use function Async\spawn;
use function Async\await;
use function Async\timeout;

// Unbuffered channel - first send fills the rendezvous slot
$ch = new Channel(0);
$ch->sendAsync("first"); // Fill the slot

$coroutine = spawn(function() use ($ch) {
    try {
        echo "Waiting for send with 50ms timeout\n";
        $ch->send("second", timeout(50));
        echo "Should not reach here\n";
    } catch (OperationCanceledException $e) {
        echo "Caught OperationCanceledException\n";
    }
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Waiting for send with 50ms timeout
Caught OperationCanceledException
Done
