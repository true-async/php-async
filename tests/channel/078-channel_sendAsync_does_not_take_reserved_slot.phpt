--TEST--
Channel: sendAsync() does not take a slot already reserved for a woken sender
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(1);
$channel->send('fill');

$reserved = spawn(function () use ($channel) {
    $channel->send('a');
    echo "S1 sent\n";
});
suspend();

// The freed slot belongs to S1 until S1 runs, so the non-blocking send has to
// report failure rather than fill it first.
$taken = $channel->recv();
echo "taken: {$taken}\n";

// isFull() reports the buffer, so it stays false while the one free slot is
// spoken for: only sendAsync() itself answers whether a value fits right now.
echo "isFull: ", var_export($channel->isFull(), true), "\n";
echo "sendAsync: ", var_export($channel->sendAsync('barge'), true), "\n";

await($reserved);

$left = $channel->recv();
echo "left: {$left}\n";
echo "Done\n";
?>
--EXPECT--
taken: fill
isFull: false
sendAsync: false
S1 sent
left: a
Done
