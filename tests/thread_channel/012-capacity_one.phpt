--TEST--
ThreadChannel: minimum capacity (1) works correctly
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel(1);

echo "Capacity: " . $ch->capacity() . "\n";

$ch->send("only_one");
echo "Full: " . ($ch->isFull() ? "yes" : "no") . "\n";

var_dump($ch->recv());
echo "Empty: " . ($ch->isEmpty() ? "yes" : "no") . "\n";

echo "Done\n";
?>
--EXPECT--
Capacity: 1
Full: yes
string(8) "only_one"
Empty: yes
Done
