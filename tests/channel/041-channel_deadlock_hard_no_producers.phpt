--TEST--
Channel: hard noProducerTimeout fires ChannelException with reason no_producers_timeout
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use function Async\spawn;

spawn(function () {
    $ch = new Channel(0, 200, 0, true);
    $start = microtime(true);
    try {
        $ch->recv();
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
reason=NO_PRODUCERS
closed=true
within=yes
