--TEST--
Channel: sendAsync - non-blocking send
--FILE--
<?php

use Async\Channel;

$ch = new Channel(2);

// Should succeed - buffer has space
$result1 = $ch->sendAsync(1);
echo "sendAsync 1: " . ($result1 ? "true" : "false") . "\n";

$result2 = $ch->sendAsync(2);
echo "sendAsync 2: " . ($result2 ? "true" : "false") . "\n";

// Should fail - buffer is full
$result3 = $ch->sendAsync(3);
echo "sendAsync 3 (full): " . ($result3 ? "true" : "false") . "\n";

// Receive one to make space
$ch->recv();

// Now should succeed
$result4 = $ch->sendAsync(4);
echo "sendAsync 4: " . ($result4 ? "true" : "false") . "\n";

// Close and try again
$ch->close();
$result5 = $ch->sendAsync(5);
echo "sendAsync 5 (closed): " . ($result5 ? "true" : "false") . "\n";

echo "Done\n";
?>
--EXPECT--
sendAsync 1: true
sendAsync 2: true
sendAsync 3 (full): false
sendAsync 4: true
sendAsync 5 (closed): false
Done
