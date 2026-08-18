--TEST--
Channel: a sender cancelled after its wakeup hands the free slot to the next sender
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(1);
$channel->send('fill');

$first = spawn(function () use ($channel) {
    try {
        $channel->send('a');
        echo "S1 sent\n";
    } catch (Async\AsyncCancellation) {
        echo "S1 cancelled\n";
    }
});
suspend();

$second = spawn(function () use ($channel) {
    $channel->send('b');
    echo "S2 sent\n";
});
suspend();

// Taking the buffered value frees one slot and wakes S1; S1 dies before it
// runs. Without a hand-off the slot stays free with every sender asleep.
$taken = $channel->recv();
echo "taken: {$taken}\n";
$first->cancel();

await($second);

$left = $channel->recv();
echo "left: {$left}\n";
echo "Done\n";
?>
--EXPECT--
taken: fill
S1 cancelled
S2 sent
left: b
Done
