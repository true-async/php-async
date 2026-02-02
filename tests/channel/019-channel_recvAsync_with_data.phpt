--TEST--
Channel: recvAsync with data returns completed future
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(2);
$ch->send("value1");
$ch->send("value2");

spawn(function() use ($ch) {
    $future1 = $ch->recvAsync();
    $future2 = $ch->recvAsync();

    echo "future1: " . $future1->await() . "\n";
    echo "future2: " . $future2->await() . "\n";
    echo "count: " . count($ch) . "\n";
});

echo "Done\n";
?>
--EXPECT--
Done
future1: value1
future2: value2
count: 0
