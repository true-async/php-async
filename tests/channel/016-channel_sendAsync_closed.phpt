--TEST--
Channel: sendAsync to closed channel returns false
--FILE--
<?php

use Async\Channel;

$ch = new Channel(1);
echo "sendAsync before close: " . ($ch->sendAsync("test") ? "true" : "false") . "\n";
echo "count: " . count($ch) . "\n";

$ch->close();
echo "sendAsync after close: " . ($ch->sendAsync("test2") ? "true" : "false") . "\n";
echo "count: " . count($ch) . "\n";
?>
--EXPECT--
sendAsync before close: true
count: 1
sendAsync after close: false
count: 1
