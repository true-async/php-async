--TEST--
Channel(0): a rendezvous the sender reported as delivered survives the receiver's cancellation
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(0);

$first = spawn(function () use ($channel) {
    try {
        $value = $channel->recv();
        echo "R1 got: {$value}\n";
    } catch (Async\AsyncCancellation) {
        echo "R1 cancelled\n";
    }
});
suspend();

$second = spawn(function () use ($channel) {
    $value = $channel->recv();
    echo "R2 got: {$value}\n";
});
suspend();

$sender = spawn(function () use ($channel) {
    $channel->send('the-value');
    echo "sender: delivered\n";
});
suspend();

// send() returned, so the message belongs to the channel: cancelling the
// receiver it was matched with must not drop it.
$first->cancel();

await($second);
await($sender);

echo "count: ", $channel->count(), "\n";
echo "Done\n";
?>
--EXPECT--
sender: delivered
R1 cancelled
R2 got: the-value
count: 0
Done
