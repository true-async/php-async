--TEST--
ThreadChannel: construct - basic instantiation
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel();
echo "Default capacity: " . $ch->capacity() . "\n";

$ch2 = new ThreadChannel(4);
echo "Capacity 4: " . $ch2->capacity() . "\n";

$ch3 = new ThreadChannel(1);
echo "Capacity 1: " . $ch3->capacity() . "\n";

echo "Done\n";
?>
--EXPECT--
Default capacity: 16
Capacity 4: 4
Capacity 1: 1
Done
