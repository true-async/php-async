--TEST--
Channel: multiple senders - concurrent producers
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(2);
$received = [];

// Multiple senders
spawn(function() use ($ch) {
    $ch->send("from-1");
    echo "Sender 1: sent\n";
});

spawn(function() use ($ch) {
    $ch->send("from-2");
    echo "Sender 2: sent\n";
});

spawn(function() use ($ch) {
    $ch->send("from-3");
    echo "Sender 3: sent\n";
});

// Single receiver
spawn(function() use ($ch, &$received) {
    for ($i = 0; $i < 3; $i++) {
        $received[] = $ch->recv();
    }
    echo "Receiver: got " . count($received) . " values\n";
    $ch->close();
});

echo "Main: done\n";
?>
--EXPECTF--
Main: done
Sender 1: sent
Sender 2: sent
Sender 3: sent
Receiver: got 3 values
