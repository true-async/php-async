--TEST--
Channel: recv from closed channel with data returns data
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(3);

// Fill buffer
$ch->send("one");
$ch->send("two");
$ch->send("three");

// Close channel
$ch->close();
echo "Channel closed with " . count($ch) . " items\n";

// Should still be able to recv existing data
spawn(function() use ($ch) {
    try {
        echo "recv 1: " . $ch->recv() . "\n";
        echo "recv 2: " . $ch->recv() . "\n";
        echo "recv 3: " . $ch->recv() . "\n";
        echo "recv 4: " . $ch->recv() . "\n";  // This should throw
    } catch (\Async\ChannelException $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
});

echo "Done\n";
?>
--EXPECT--
Channel closed with 3 items
Done
recv 1: one
recv 2: two
recv 3: three
Exception: Channel is closed
