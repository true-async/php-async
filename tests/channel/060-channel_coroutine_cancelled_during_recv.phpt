--TEST--
Channel: a single coroutine cancelled while blocked on recv — cancellation wins, channel stays usable
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$ch = new Channel(0, 0, 0);

$victim = spawn(function () use ($ch) {
    try {
        $ch->recv();
        return "FAIL_RETURNED";
    } catch (\Throwable $e) {
        return get_class($e);
    }
});

spawn(function () use ($victim) { delay(40); $victim->cancel(); });

$result = await($victim);
echo "victim_result=", $result, "\n";

// Channel is still alive and usable from a different coroutine.
echo "ch_closed=", $ch->isClosed() ? "true" : "false", "\n";

$producer = spawn(function () use ($ch) { delay(20); $ch->send("late"); });
$consumer = spawn(function () use ($ch) { return $ch->recv(); });
echo "consumer_got=", await($consumer), "\n";
await($producer);
echo "ok\n";
?>
--EXPECT--
victim_result=Async\AsyncCancellation
ch_closed=false
consumer_got=late
ok
