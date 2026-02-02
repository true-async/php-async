--TEST--
Channel: recv with timeout throws TimeoutException
--FILE--
<?php

use Async\Channel;
use Async\TimeoutException;
use function Async\spawn;
use function Async\await;

$ch = new Channel(0);

$coroutine = spawn(function() use ($ch) {
    try {
        echo "Waiting for recv with 50ms timeout\n";
        $ch->recv(50);
        echo "Should not reach here\n";
    } catch (TimeoutException $e) {
        echo "Caught TimeoutException\n";
    }
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Waiting for recv with 50ms timeout
Caught TimeoutException
Done
