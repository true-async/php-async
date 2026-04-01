--TEST--
ThreadChannel: recv on closed channel drains remaining data
--FILE--
<?php

use Async\ThreadChannel;
use Async\ThreadChannelException;

$ch = new ThreadChannel(4);
$ch->send("a");
$ch->send("b");
$ch->close();

// Should still be able to recv buffered data
var_dump($ch->recv());
var_dump($ch->recv());

// Now buffer is empty + closed — should throw
try {
    $ch->recv();
} catch (ThreadChannelException $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
string(1) "a"
string(1) "b"
Caught: ThreadChannel is closed
Done
