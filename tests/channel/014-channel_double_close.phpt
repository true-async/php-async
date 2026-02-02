--TEST--
Channel: double close is idempotent
--FILE--
<?php

use Async\Channel;

$ch = new Channel(1);
$ch->send("test");

$ch->close();
echo "First close: ok\n";

$ch->close();
echo "Second close: ok\n";

echo "isClosed: " . ($ch->isClosed() ? "true" : "false") . "\n";
?>
--EXPECT--
First close: ok
Second close: ok
isClosed: true
