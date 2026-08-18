--TEST--
Channel(0): the slot freed by a delivery is reserved for the sender already waiting
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(0);

$holder = spawn(function () use ($channel) {
    $channel->send('first');
    echo "S1 returned\n";
});
suspend();

$waiting = spawn(function () use ($channel) {
    $channel->send('second');
    echo "S2 returned\n";
});
suspend();

// Taking the value owes two answers at once: S1 hears its message was taken,
// and S2 gets the slot that message leaves behind. Paying only the first would
// leave the slot open for one tick, long enough for a newcomer to take it ahead
// of S2.
$taken = $channel->recv();
echo "recv: {$taken}\n";
echo "barge: ", var_export($channel->sendAsync('barge'), true), "\n";

$drain = spawn(function () use ($channel) {
    $value = $channel->recv();
    echo "drained: {$value}\n";
});

await($holder);
await($waiting);
await($drain);
echo "Done\n";
?>
--EXPECT--
recv: first
barge: false
S1 returned
drained: second
S2 returned
Done
