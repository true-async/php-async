--TEST--
ThreadChannel: recv on closed empty channel throws exception
--FILE--
<?php

use Async\ThreadChannel;
use Async\ThreadChannelException;

$ch = new ThreadChannel(4);
$ch->close();

try {
    $ch->recv();
} catch (ThreadChannelException $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
Caught: ThreadChannel is closed
Done
