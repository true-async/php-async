--TEST--
Channel: recv with cancellation token (timeout) throws OperationCanceledException
--FILE--
<?php

use Async\Channel;
use Async\OperationCanceledException;
use function Async\spawn;
use function Async\await;
use function Async\timeout;

$ch = new Channel(0);

$coroutine = spawn(function() use ($ch) {
    try {
        echo "Waiting for recv with 50ms timeout\n";
        $ch->recv(timeout(50));
        echo "Should not reach here\n";
    } catch (OperationCanceledException $e) {
        echo "Caught OperationCanceledException\n";
    }
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Waiting for recv with 50ms timeout
Caught OperationCanceledException
Done
