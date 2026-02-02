--TEST--
Channel: close interrupts waiting coroutines
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(1);
$ch->send("fill");

// Sender waiting for space
spawn(function() use ($ch) {
    try {
        echo "Sender: waiting\n";
        $ch->send("blocked");
        echo "Sender: should not reach\n";
    } catch (\Async\ChannelException $e) {
        echo "Sender: " . $e->getMessage() . "\n";
    }
});

// Receiver that will close
spawn(function() use ($ch) {
    echo "Closer: about to close\n";
    $ch->close();
    echo "Closer: closed\n";
});

echo "Main done\n";
?>
--EXPECT--
Main done
Sender: waiting
Closer: about to close
Closer: closed
Sender: Channel is closed
