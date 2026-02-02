--TEST--
Channel: recvAsync pending future rejects when channel is closed
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$ch = new Channel(0);

$coroutine = spawn(function() use ($ch) {
    echo "Getting future from empty channel\n";
    $future = $ch->recvAsync();

    // Close channel after a short delay
    spawn(function() use ($ch) {
        delay(10);
        echo "Closing channel\n";
        $ch->close();
    });

    // Use catch() method to handle rejection
    $future->catch(function(ChannelException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    })->await();
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Getting future from empty channel
Closing channel
Caught: Channel is closed
Done
