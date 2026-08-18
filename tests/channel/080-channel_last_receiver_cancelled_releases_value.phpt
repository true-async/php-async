--TEST--
Channel: the value becomes free again when the only woken receiver is cancelled
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(1);

$only = spawn(function () use ($channel) {
    try {
        $value = $channel->recv();
        echo "R1 got: {$value}\n";
    } catch (Async\AsyncCancellation) {
        echo "R1 cancelled\n";
    }
});
suspend();

// Nobody is left to hand the value to, so the reservation has to be dropped
// rather than kept: a value nobody may take is the deadlock this guards.
$channel->send('the-value');
$only->cancel();
await($only);

$value = $channel->recv();
echo "main got: {$value}\n";
echo "count: ", $channel->count(), "\n";
echo "Done\n";
?>
--EXPECT--
R1 cancelled
main got: the-value
count: 0
Done
