--TEST--
Channel: capacity 1 edge case
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

$ch = new Channel(1);

echo "capacity: " . $ch->capacity() . "\n";
echo "isEmpty: " . ($ch->isEmpty() ? "true" : "false") . "\n";
echo "isFull: " . ($ch->isFull() ? "true" : "false") . "\n";

$ch->send("one");
echo "After send:\n";
echo "isEmpty: " . ($ch->isEmpty() ? "true" : "false") . "\n";
echo "isFull: " . ($ch->isFull() ? "true" : "false") . "\n";
echo "count: " . count($ch) . "\n";

// Second send should block
spawn(function() use ($ch) {
    echo "Sender: sending two\n";
    $ch->send("two");
    echo "Sender: sent two\n";
});

spawn(function() use ($ch) {
    echo "Receiver: recv 1: " . $ch->recv() . "\n";
    echo "Receiver: recv 2: " . $ch->recv() . "\n";
});

echo "Main done\n";
?>
--EXPECT--
capacity: 1
isEmpty: true
isFull: false
After send:
isEmpty: false
isFull: true
count: 1
Main done
Sender: sending two
Receiver: recv 1: one
Sender: sent two
Receiver: recv 2: two
