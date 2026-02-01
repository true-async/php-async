--TEST--
Channel: close - basic close behavior
--FILE--
<?php

use Async\Channel;

$ch = new Channel(3);

echo "isClosed before: " . ($ch->isClosed() ? "true" : "false") . "\n";

$ch->send(1);
$ch->send(2);

$ch->close();

echo "isClosed after: " . ($ch->isClosed() ? "true" : "false") . "\n";

// Double close is safe
$ch->close();
echo "Double close: OK\n";

echo "Done\n";
?>
--EXPECT--
isClosed before: false
isClosed after: true
Double close: OK
Done
