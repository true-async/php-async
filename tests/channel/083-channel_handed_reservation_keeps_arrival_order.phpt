--TEST--
Channel: a reservation handed on by a cancelled receiver goes to the oldest waiter
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(1);

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

$third = spawn(function () use ($channel) {
    $value = $channel->recv();
    echo "R3 got: {$value}\n";
});
suspend();

// Two receivers are queued behind the one that dies, so the value it leaves
// behind tells them apart: it belongs to the one that has waited longer.
$channel->send('first');
$first->cancel();
await($second);

$channel->send('second');
await($third);

echo "Done\n";
?>
--EXPECT--
R1 cancelled
R2 got: first
R3 got: second
Done
