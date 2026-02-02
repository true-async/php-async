--TEST--
Channel: unbuffered sendAsync behavior
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(0); // unbuffered

echo "First sendAsync: " . ($ch->sendAsync("one") ? "true" : "false") . "\n";
echo "isFull: " . ($ch->isFull() ? "true" : "false") . "\n";
echo "count: " . count($ch) . "\n";

echo "Second sendAsync: " . ($ch->sendAsync("two") ? "true" : "false") . "\n";
echo "count: " . count($ch) . "\n";

spawn(function() use ($ch) {
    echo "Receiver: " . $ch->recv() . "\n";
    echo "After recv, isFull: " . ($ch->isFull() ? "true" : "false") . "\n";
});

echo "Done\n";
?>
--EXPECT--
First sendAsync: true
isFull: true
count: 1
Second sendAsync: false
count: 1
Done
Receiver: one
After recv, isFull: false
