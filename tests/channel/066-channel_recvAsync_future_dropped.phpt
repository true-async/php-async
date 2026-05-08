--TEST--
Channel: recvAsync() Future dropped before channel sends — channel does not write to freed memory
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

$ch = new Async\Channel(0);

echo "start\n";

// Create a Future via recvAsync and DROP it without await. The channel still
// has a queued waiter pointing to the (now freed) future. Without the fix,
// the next send() would write to freed memory → heap corruption / UAF.
$future = $ch->recvAsync();
$future->ignore();
unset($future);
echo "future dropped\n";

// Sender now wakes the channel. The waiter must have been removed by the
// future's dispose path, so the channel falls back to either buffering or
// sees no receiver — in either case no crash.
spawn(function () use ($ch) {
    delay(20);
    // recvAsync's queued waiter is gone (removed by future dispose), so the
    // send just stages the value into the rendezvous slot. The point of the
    // test is that this does NOT corrupt heap by writing to a freed future.
    $ch->sendAsync("hello");
    echo "sendAsync done\n";
});

delay(60);
echo "ok\n";

?>
--EXPECT--
start
future dropped
sendAsync done
ok
