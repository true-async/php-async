--TEST--
Channel(0): a send() that throws leaves no value behind for a later recv()
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(0);

$sender = spawn(function () use ($channel) {
    try {
        $channel->send('X');
        echo "sender: sent\n";
    } catch (Async\AsyncCancellation) {
        echo "sender: cancelled\n";
    }
});
suspend();

// The value is in the slot but no receiver has been matched with it, so the
// sender is still the only one who knows about it. Cancelling it must take the
// value along: the caller is told the message never went, and a message that
// never went may not turn up in someone's recv().
$sender->cancel();
await($sender);

echo "count: ", $channel->count(), "\n";
echo "isEmpty: ", var_export($channel->isEmpty(), true), "\n";
echo "Done\n";
?>
--EXPECT--
sender: cancelled
count: 0
isEmpty: true
Done
