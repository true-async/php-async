--TEST--
Channel: blocking - send blocks when buffer is full
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(2);

spawn(function() use ($ch) {
    echo "Sender: send 1\n";
    $ch->send(1);
    echo "Sender: send 2\n";
    $ch->send(2);
    echo "Sender: send 3 (will block)\n";
    $ch->send(3);
    echo "Sender: send 3 completed\n";
});

spawn(function() use ($ch) {
    echo "Receiver: waiting a bit\n";
    // Let sender fill the buffer
    \Async\suspend();
    \Async\suspend();

    echo "Receiver: recv\n";
    $v = $ch->recv();
    echo "Receiver: got $v\n";
});

echo "Main: done\n";
?>
--EXPECT--
Main: done
Sender: send 1
Sender: send 2
Sender: send 3 (will block)
Receiver: waiting a bit
Receiver: recv
Receiver: got 1
Sender: send 3 completed
