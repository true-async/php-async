--TEST--
Channel: close wakes waiting receiver with exception
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(0);

// Receiver waiting for data from empty channel
spawn(function() use ($ch) {
    try {
        echo "Receiver: waiting\n";
        $ch->recv();
        echo "Receiver: should not reach here\n";
    } catch (\Async\ChannelException $e) {
        echo "Receiver: " . $e->getMessage() . "\n";
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
Receiver: waiting
Closer: closing
Closer: done
Receiver: Channel is closed
