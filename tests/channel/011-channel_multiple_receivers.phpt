--TEST--
Channel: multiple receivers - concurrent consumers
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(0); // unbuffered for strict ordering

// Single sender
spawn(function() use ($ch) {
    for ($i = 1; $i <= 3; $i++) {
        echo "Sender: sending $i\n";
        $ch->send($i);
    }
    $ch->close();
    echo "Sender: closed\n";
});

// Multiple receivers - only one gets each value
spawn(function() use ($ch) {
    try {
        while (true) {
            $v = $ch->recv();
            echo "Receiver A: got $v\n";
        }
    } catch (\Async\ChannelException $e) {
        echo "Receiver A: channel closed\n";
    }
});

spawn(function() use ($ch) {
    try {
        while (true) {
            $v = $ch->recv();
            echo "Receiver B: got $v\n";
        }
    } catch (\Async\ChannelException $e) {
        echo "Receiver B: channel closed\n";
    }
});

echo "Main: done\n";
?>
--EXPECT--
Main: done
Sender: sending 1
Receiver A: got 1
Sender: sending 2
Sender: sending 3
Receiver A: got 2
Sender: closed
Receiver B: got 3
Receiver B: channel closed
Receiver A: channel closed
