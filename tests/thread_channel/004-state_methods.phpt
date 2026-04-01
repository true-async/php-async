--TEST--
ThreadChannel: isEmpty, isFull, count state tracking
--FILE--
<?php

use Async\ThreadChannel;

$ch = new ThreadChannel(2);

echo "Initial: empty=" . ($ch->isEmpty() ? "yes" : "no");
echo " full=" . ($ch->isFull() ? "yes" : "no");
echo " count=" . $ch->count() . "\n";

$ch->send("a");
echo "After 1 send: empty=" . ($ch->isEmpty() ? "yes" : "no");
echo " full=" . ($ch->isFull() ? "yes" : "no");
echo " count=" . $ch->count() . "\n";

$ch->send("b");
echo "After 2 sends: empty=" . ($ch->isEmpty() ? "yes" : "no");
echo " full=" . ($ch->isFull() ? "yes" : "no");
echo " count=" . $ch->count() . "\n";

$ch->recv();
echo "After 1 recv: empty=" . ($ch->isEmpty() ? "yes" : "no");
echo " full=" . ($ch->isFull() ? "yes" : "no");
echo " count=" . $ch->count() . "\n";

$ch->recv();
echo "After 2 recvs: empty=" . ($ch->isEmpty() ? "yes" : "no");
echo " full=" . ($ch->isFull() ? "yes" : "no");
echo " count=" . $ch->count() . "\n";

echo "Done\n";
?>
--EXPECT--
Initial: empty=yes full=no count=0
After 1 send: empty=no full=no count=1
After 2 sends: empty=no full=yes count=2
After 1 recv: empty=no full=no count=1
After 2 recvs: empty=yes full=no count=0
Done
