--TEST--
Channel(0): close() right after the value is taken must not fail the sender that sent it
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(0);

$sender = spawn(function () use ($channel) {
    try {
        $channel->send('the-value');
        echo "sender: delivered\n";
    } catch (Async\ChannelException $e) {
        echo "sender: ", $e->getMessage(), "\n";
    }
});
suspend();

// The sender is parked on its own value, waiting to hear that a receiver took
// it. Taking the value is that answer; a close() arriving before the sender
// runs must not turn a delivery the receiver already holds into a failure.
$taken = $channel->recv();
echo "recv: {$taken}\n";
$channel->close();

await($sender);
echo "Done\n";
?>
--EXPECT--
recv: the-value
sender: delivered
Done
