--TEST--
Channel: unbuffered - rendezvous semantics
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

// Unbuffered channel: send blocks until recv
$ch = new Channel(0);

spawn(function() use ($ch) {
    echo "Sender: about to send\n";
    $ch->send("hello");
    echo "Sender: send completed\n";
});

spawn(function() use ($ch) {
    echo "Receiver: about to recv\n";
    $value = $ch->recv();
    echo "Receiver: got $value\n";
});

echo "Main: done\n";
?>
--EXPECT--
Main: done
Sender: about to send
Sender: send completed
Receiver: about to recv
Receiver: got hello
