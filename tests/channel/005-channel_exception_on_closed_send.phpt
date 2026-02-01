--TEST--
Channel: exception - send to closed channel throws
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use function Async\spawn;

$ch = new Channel(3);
$ch->close();

spawn(function() use ($ch) {
    try {
        $ch->send("value");
        echo "ERROR: should have thrown\n";
    } catch (ChannelException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
});

echo "Done\n";
?>
--EXPECT--
Done
Caught: Channel is closed
