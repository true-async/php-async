--TEST--
Channel: await_any_or_fail with multiple recvAsync Futures — losers must clean up
--FILE--
<?php

use function Async\spawn;
use function Async\delay;
use function Async\await_any_or_fail;

$ch1 = new Async\Channel(0);
$ch2 = new Async\Channel(0);

spawn(function () use ($ch1) {
    delay(20);
    $ch1->sendAsync("from-1");
});

// Wait for the first of two recv futures. Once $ch1 fires, the future from
// $ch2->recvAsync() is dropped while its waiter is still queued in $ch2.
// The waiter must be removed during future dispose so $ch2 can be GC'd
// cleanly and a later $ch2->sendAsync() doesn't poke freed memory.
$result = await_any_or_fail([
    $ch1->recvAsync(),
    $ch2->recvAsync(),
]);

echo "got: $result\n";

// Now exercise the loser channel — its waiter from the dropped future must
// already be gone, otherwise this would corrupt heap.
$ch2->sendAsync("ignored");
echo "ok\n";

?>
--EXPECT--
got: from-1
ok
