--TEST--
Channel: with no soft-timer, deadlock detector cancels coroutines, channel still freed cleanly
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use function Async\spawn;
use function Async\await;

// Disabled timeouts → no soft registration → no global resolver path.
// Lone receiver blocks → scheduler hits resolve_deadlocks → cancels.
$ch = new Channel(0, 0, 0);

$task = spawn(function () use ($ch) {
    try {
        $ch->recv();
        return "FAIL_RETURNED";
    } catch (\Throwable $e) {
        return get_class($e);
    }
});

try {
    $result = await($task);
    echo "result=", $result, "\n";
} catch (\Throwable $e) {
    echo "await_threw=", get_class($e), "\n";
}
echo "ch_closed=", $ch->isClosed() ? "true" : "false", "\n";
?>
--EXPECTF--
%A