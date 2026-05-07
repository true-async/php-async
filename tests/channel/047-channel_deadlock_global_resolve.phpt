--TEST--
Channel: soft timer triggers global deadlock resolver before its own timeout
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use function Async\spawn;

spawn(function () {
    // 5s soft timeout — should resolve "instantly" via the global resolver,
    // not wait for the per-channel timer.
    $ch = new Channel(0, 5000, 5000, false);
    $start = microtime(true);
    try {
        $ch->recv();
    } catch (ChannelException $e) {
        $elapsed = (int) ((microtime(true) - $start) * 1000);
        $fast = ($elapsed < 1000) ? "yes" : "no ($elapsed)";
        echo "reason=", $e->reason->name, "\n";
        echo "fast=", $fast, "\n";
        echo "closed=", $ch->isClosed() ? "true" : "false", "\n";
    }
});
?>
--EXPECT--
reason=DEADLOCK
fast=yes
closed=true
