--TEST--
Channel: iterator - foreach support
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(3);

spawn(function() use ($ch) {
    $ch->send("a");
    $ch->send("b");
    $ch->send("c");
    $ch->close();
    echo "Sender: closed\n";
});

spawn(function() use ($ch) {
    echo "Receiver: starting foreach\n";
    foreach ($ch as $value) {
        echo "Received: $value\n";
    }
    echo "Receiver: foreach ended\n";
});

echo "Main: done\n";
?>
--EXPECT--
Main: done
Sender: closed
Receiver: starting foreach
Received: a
Received: b
Received: c
Receiver: foreach ended
