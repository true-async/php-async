--TEST--
Channel: recvAsync() does not take a value already reserved for a woken receiver
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

$channel->send('first');

$future = $channel->recvAsync();
echo "future completed at once: ", var_export($future->isCompleted(), true), "\n";

$feeder = spawn(function () use ($channel) {
    $channel->send('second');
});

$value = await($future);
echo "future got: {$value}\n";

await($reserved);
await($feeder);
echo "Done\n";
?>
--EXPECT--
future completed at once: false
R1 got: first
future got: second
Done
