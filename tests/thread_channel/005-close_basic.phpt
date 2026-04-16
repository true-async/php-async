--TEST--
ThreadChannel: close - basic close and isClosed
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel(4);
echo "Before close: " . ($ch->isClosed() ? "closed" : "open") . "\n";

$ch->close();
echo "After close: " . ($ch->isClosed() ? "closed" : "open") . "\n";

// Double close is a no-op
$ch->close();
echo "After double close: " . ($ch->isClosed() ? "closed" : "open") . "\n";

echo "Done\n";
?>
--EXPECT--
Before close: open
After close: closed
After double close: closed
Done
