--TEST--
Channel: foreach does not take a value already reserved for a woken receiver
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
$feeder = spawn(function () use ($channel) {
    $channel->send('second');
});

foreach ($channel as $value) {
    echo "loop got: {$value}\n";
    break;
}

await($reserved);
await($feeder);
echo "Done\n";
?>
--EXPECT--
R1 got: first
loop got: second
Done
