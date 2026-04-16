--TEST--
ThreadChannel: send and recv from coroutines (single thread)
--FILE--
<?php

use Async\ThreadChannel;
use function Async\spawn;

$ch = new ThreadChannel(4);

spawn(function() use ($ch) {
    $ch->send(1);
    $ch->send(2);
    $ch->send(3);
    echo "All sent\n";
});

spawn(function() use ($ch) {
    echo "Got: " . $ch->recv() . "\n";
    echo "Got: " . $ch->recv() . "\n";
    echo "Got: " . $ch->recv() . "\n";
});

echo "Done\n";
?>
--EXPECT--
Done
All sent
Got: 1
Got: 2
Got: 3
