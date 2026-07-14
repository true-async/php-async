--TEST--
Channel: foreach ends cleanly when the channel is closed while the consumer is parked
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

echo "start\n";

// The iterator parks in channel_wait_for() and is woken with a ChannelException when close() lands.
// It used to stop the loop but leave that exception pending, so it escaped foreach() uncaught --
// and surfaced against the producer's close(), which had done nothing wrong.
$channel = new Async\Channel(2);

$consumer = spawn(function () use ($channel) {
    foreach ($channel as $value) {
        echo "got: $value\n";
    }

    echo "loop finished normally\n";
});

$producer = spawn(function () use ($channel) {
    delay(20);
    $channel->send('a');
    $channel->send('b');
    delay(20);
    $channel->close();
    echo "producer closed\n";
});

await($producer);
await($consumer);

echo "done\n";
?>
--EXPECT--
start
got: a
got: b
producer closed
loop finished normally
done
