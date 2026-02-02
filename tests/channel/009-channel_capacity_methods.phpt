--TEST--
Channel: capacity methods - count, isEmpty, isFull
--FILE--
<?php

use Async\Channel;

$ch = new Channel(3);

echo "Initial state:\n";
echo "  capacity: " . $ch->capacity() . "\n";
echo "  count: " . $ch->count() . "\n";
echo "  isEmpty: " . ($ch->isEmpty() ? "true" : "false") . "\n";
echo "  isFull: " . ($ch->isFull() ? "true" : "false") . "\n";

$ch->send(1);
echo "\nAfter 1 send:\n";
echo "  count: " . $ch->count() . "\n";
echo "  isEmpty: " . ($ch->isEmpty() ? "true" : "false") . "\n";
echo "  isFull: " . ($ch->isFull() ? "true" : "false") . "\n";

$ch->send(2);
$ch->send(3);
echo "\nAfter 3 sends (full):\n";
echo "  count: " . $ch->count() . "\n";
echo "  isEmpty: " . ($ch->isEmpty() ? "true" : "false") . "\n";
echo "  isFull: " . ($ch->isFull() ? "true" : "false") . "\n";

$ch->recv();
echo "\nAfter 1 recv:\n";
echo "  count: " . $ch->count() . "\n";
echo "  isEmpty: " . ($ch->isEmpty() ? "true" : "false") . "\n";
echo "  isFull: " . ($ch->isFull() ? "true" : "false") . "\n";

echo "Done\n";
?>
--EXPECT--
Initial state:
  capacity: 3
  count: 0
  isEmpty: true
  isFull: false

After 1 send:
  count: 1
  isEmpty: false
  isFull: false

After 3 sends (full):
  count: 3
  isEmpty: false
  isFull: true

After 1 recv:
  count: 2
  isEmpty: false
  isFull: false
Done
