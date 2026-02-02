--TEST--
Channel: recvAsync on closed empty channel returns rejected future
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(1);
$ch->close();

spawn(function() use ($ch) {
    $future = $ch->recvAsync();

    try {
        $future->await();
        echo "Should not reach here\n";
    } catch (\Async\ChannelException $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
});

echo "Done\n";
?>
--EXPECT--
Done
Exception: Channel is closed
