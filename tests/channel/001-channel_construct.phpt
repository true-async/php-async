--TEST--
Channel: construct - basic instantiation
--FILE--
<?php

use Async\Channel;

// Unbuffered channel (rendezvous)
$ch1 = new Channel();
echo "Unbuffered channel created\n";
echo "Capacity: " . $ch1->capacity() . "\n";

// Buffered channel
$ch2 = new Channel(5);
echo "Buffered channel created\n";
echo "Capacity: " . $ch2->capacity() . "\n";

// Zero capacity is the same as unbuffered
$ch3 = new Channel(0);
echo "Zero capacity channel created\n";
echo "Capacity: " . $ch3->capacity() . "\n";

echo "Done\n";
?>
--EXPECT--
Unbuffered channel created
Capacity: 0
Buffered channel created
Capacity: 5
Zero capacity channel created
Capacity: 0
Done
