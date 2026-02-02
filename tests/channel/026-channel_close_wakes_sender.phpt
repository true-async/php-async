--TEST--
Channel: close wakes waiting sender with exception
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(0);

// Fill the channel first
$ch->sendAsync("block");

// Sender waiting for space in full channel
spawn(function() use ($ch) {
    try {
        echo "Sender: waiting\n";
        $ch->send("waiting");
        echo "Sender: should not reach here\n";
    } catch (\Async\ChannelException $e) {
        echo "Sender: " . $e->getMessage() . "\n";
    }
});

// Another coroutine that closes the channel
spawn(function() use ($ch) {
    echo "Closer: closing\n";
    $ch->close();
    echo "Closer: done\n";
});

echo "Main done\n";
?>
--EXPECT--
Main done
Sender: waiting
Closer: closing
Closer: done
Sender: Channel is closed
