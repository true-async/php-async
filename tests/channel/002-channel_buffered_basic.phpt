--TEST--
Channel: buffered - basic send and recv
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await;

$ch = new Channel(3);

spawn(function() use ($ch) {
    echo "Sending 1\n";
    $ch->send(1);
    echo "Sending 2\n";
    $ch->send(2);
    echo "Sending 3\n";
    $ch->send(3);
    echo "All sent\n";
});

spawn(function() use ($ch) {
    echo "Receiving...\n";
    $v1 = $ch->recv();
    echo "Received: $v1\n";
    $v2 = $ch->recv();
    echo "Received: $v2\n";
    $v3 = $ch->recv();
    echo "Received: $v3\n";
});

echo "Done\n";
?>
--EXPECT--
Done
Sending 1
Sending 2
Sending 3
All sent
Receiving...
Received: 1
Received: 2
Received: 3
