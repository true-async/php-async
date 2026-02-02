--TEST--
Channel: recvAsync on empty channel returns pending future that resolves when data arrives
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$ch = new Channel(0);

$coroutine = spawn(function() use ($ch) {
    echo "Getting future from empty channel\n";
    $future = $ch->recvAsync();
    echo "Got future, it should be pending\n";

    // Send data after a short delay
    spawn(function() use ($ch) {
        delay(10);
        echo "Sending value\n";
        $ch->sendAsync("hello");
    });

    // Await the future - it should resolve when data arrives
    $result = $future->await();
    echo "Received: $result\n";
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Getting future from empty channel
Got future, it should be pending
Sending value
Received: hello
Done
