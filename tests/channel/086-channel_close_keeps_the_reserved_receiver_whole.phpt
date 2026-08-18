--TEST--
Channel: close() does not take back a value already promised to a woken receiver
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(2);

$reserved = spawn(function () use ($channel) {
    try {
        $value = $channel->recv();
        echo "R1 got: {$value}\n";
    } catch (Async\ChannelException $e) {
        echo "R1: ", $e->getMessage(), "\n";
    }
});
suspend();

$latecomer = spawn(function () use ($channel) {
    try {
        $value = $channel->recv();
        echo "R2 got: {$value}\n";
    } catch (Async\ChannelException $e) {
        echo "R2: ", $e->getMessage(), "\n";
    }
});
suspend();

// R1 is woken with a value of its own but has not run yet; R2 is still waiting
// for one. close() has to tell them apart: the first is owed a value, the
// second is owed nothing.
$channel->send('the-value');
$channel->close();

await($reserved);
await($latecomer);
echo "Done\n";
?>
--EXPECT--
R1 got: the-value
R2: Channel is closed
Done
