--TEST--
Channel: explicit close() sets reason "explicit" on ChannelException
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;

$ch = new Channel(0, 0, 0);
$ch->close();

try {
    $ch->send(1);
} catch (ChannelException $e) {
    echo "send.reason=", $e->reason->name, "\n";
}

$ch2 = new Channel(2, 0, 0);
$ch2->send("a");
$ch2->close();

try {
    // After explicit close + buffer-pending send, recv still drains "a"
    // (the existing channel behaviour is preserved). Once empty, recv would
    // throw ChannelException with reason EXPLICIT.
    $v = $ch2->recv();
    echo "recv.value=", $v, "\n";
} catch (ChannelException $e) {
    echo "recv.reason=", $e->reason->name, "\n";
}
?>
--EXPECT--
send.reason=EXPLICIT
recv.value=a
