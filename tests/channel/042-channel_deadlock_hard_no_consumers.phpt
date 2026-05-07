--TEST--
Channel: hard noConsumerTimeout fires ChannelException with reason no_consumers_timeout
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use function Async\spawn;

spawn(function () {
    $ch = new Channel(1, 0, 200, true);
    $ch->send("first"); // fills buffer
    $start = microtime(true);
    try {
        $ch->send("second"); // blocks then times out
        echo "FAIL\n";
    } catch (ChannelException $e) {
        $elapsed = (int) ((microtime(true) - $start) * 1000);
        $within = ($elapsed >= 180 && $elapsed < 600) ? "yes" : "no ($elapsed)";
        echo "reason=", $e->reason->name, "\n";
        echo "closed=", $ch->isClosed() ? "true" : "false", "\n";
        echo "within=", $within, "\n";
    }
});
?>
--EXPECT--
reason=NO_CONSUMERS
closed=true
within=yes
