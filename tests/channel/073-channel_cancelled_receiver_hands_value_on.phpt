--TEST--
Channel: a receiver cancelled after its wakeup hands the value to the next receiver
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

// The value wakes R1, which dies before it runs. The wakeup has to reach R2
// instead of dying with R1 and leaving the value unreachable in the buffer.
$channel->send('the-value');
$first->cancel();

await($second);

echo "count: ", $channel->count(), "\n";
echo "Done\n";
?>
--EXPECT--
R1 cancelled
R2 got: the-value
count: 0
Done
