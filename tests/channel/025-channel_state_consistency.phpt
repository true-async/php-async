--TEST--
Channel: state consistency after operations
--FILE--
<?php

use Async\Channel;

$ch = new Channel(3);

echo "Initial state:\n";
echo "  count=" . count($ch) . " isEmpty=" . ($ch->isEmpty() ? "Y" : "N") . " isFull=" . ($ch->isFull() ? "Y" : "N") . "\n";

$ch->send("a");
echo "After 1 send:\n";
echo "  count=" . count($ch) . " isEmpty=" . ($ch->isEmpty() ? "Y" : "N") . " isFull=" . ($ch->isFull() ? "Y" : "N") . "\n";

$ch->send("b");
echo "After 2 sends:\n";
echo "  count=" . count($ch) . " isEmpty=" . ($ch->isEmpty() ? "Y" : "N") . " isFull=" . ($ch->isFull() ? "Y" : "N") . "\n";

$ch->send("c");
echo "After 3 sends (full):\n";
echo "  count=" . count($ch) . " isEmpty=" . ($ch->isEmpty() ? "Y" : "N") . " isFull=" . ($ch->isFull() ? "Y" : "N") . "\n";

// Cannot add more without blocking
echo "sendAsync when full: " . ($ch->sendAsync("d") ? "true" : "false") . "\n";

// Now recv
\Async\spawn(function() use ($ch) {
    $ch->recv();
    echo "After 1 recv:\n";
    echo "  count=" . count($ch) . " isEmpty=" . ($ch->isEmpty() ? "Y" : "N") . " isFull=" . ($ch->isFull() ? "Y" : "N") . "\n";

    $ch->recv();
    $ch->recv();
    echo "After 3 recvs:\n";
    echo "  count=" . count($ch) . " isEmpty=" . ($ch->isEmpty() ? "Y" : "N") . " isFull=" . ($ch->isFull() ? "Y" : "N") . "\n";
});

echo "Done\n";
?>
--EXPECT--
Initial state:
  count=0 isEmpty=Y isFull=N
After 1 send:
  count=1 isEmpty=N isFull=N
After 2 sends:
  count=2 isEmpty=N isFull=N
After 3 sends (full):
  count=3 isEmpty=N isFull=Y
sendAsync when full: false
Done
After 1 recv:
  count=2 isEmpty=N isFull=N
After 3 recvs:
  count=0 isEmpty=Y isFull=N
