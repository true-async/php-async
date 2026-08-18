--TEST--
Channel(0): a delivery already reported by send() is not undone by close()
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(0);

$only = spawn(function () use ($channel) {
    try {
        $value = $channel->recv();
        echo "R1 got: {$value}\n";
    } catch (Async\AsyncCancellation) {
        echo "R1 cancelled\n";
    }
});
suspend();

$sender = spawn(function () use ($channel) {
    $channel->send('the-value');
    echo "sender: delivered\n";
});
suspend();

// The matched receiver dies with nobody behind it, so the reservation is
// dropped. What the sender was told is a different fact and outlives it: the
// value stays committed to the channel, and close() rolls back only what was
// never reported as delivered.
$only->cancel();
await($only);
await($sender);

$channel->close();

echo "closed: ", var_export($channel->isClosed(), true), "\n";
echo "late recv: ", $channel->recv(), "\n";
echo "Done\n";
?>
--EXPECT--
sender: delivered
R1 cancelled
closed: true
late recv: the-value
Done
