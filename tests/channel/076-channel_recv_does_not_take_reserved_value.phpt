--TEST--
Channel: recv() does not take a value already reserved for a woken receiver
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$channel = new Channel(1);

$reserved = spawn(function () use ($channel) {
    $value = $channel->recv();
    echo "R1 got: {$value}\n";
});
suspend();

// 'first' is handed to R1, which is queued but has not run yet. The caller
// below runs before it and must wait for a value of its own.
$channel->send('first');
$feeder = spawn(function () use ($channel) {
    $channel->send('second');
});

$value = $channel->recv();
echo "main got: {$value}\n";

await($reserved);
await($feeder);
echo "Done\n";
?>
--EXPECT--
R1 got: first
main got: second
Done
