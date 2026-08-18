--TEST--
Channel: three senders queued on a full buffer are served in arrival order
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(1);
$channel->send('fill');

$senders = [];
foreach (['a', 'b', 'c'] as $name) {
    $senders[] = spawn(function () use ($channel, $name) {
        $channel->send($name);
    });
    suspend();
}

// Three is the smallest queue that tells arrival order from any other: with two
// waiters every order looks like a queue.
$order = [$channel->recv()];
for ($i = 0; $i < 3; $i++) {
    $order[] = $channel->recv();
}

foreach ($senders as $sender) {
    await($sender);
}

echo implode(' ', $order), "\n";
echo "Done\n";
?>
--EXPECT--
fill a b c
Done
