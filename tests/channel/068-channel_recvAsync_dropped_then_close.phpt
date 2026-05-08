--TEST--
Channel: recvAsync() Future dropped, then channel closes — close path skips freed waiter
--FILE--
<?php

use function Async\spawn;
use function Async\delay;

$ch = new Async\Channel(0);

$future = $ch->recvAsync();
$future->ignore();
unset($future);
echo "future dropped\n";

// channel_close() walks all queued future waiters and rejects them. With the
// fix, the dropped future already removed its waiter from the queue, so the
// close-time iteration is empty.
spawn(function () use ($ch) {
    delay(20);
    $ch->close();
    echo "channel closed\n";
});

delay(40);
echo "ok\n";

?>
--EXPECT--
future dropped
channel closed
ok
