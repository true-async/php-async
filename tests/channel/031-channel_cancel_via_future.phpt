--TEST--
Channel: recv and send cancelled via arbitrary Future cancellation token
--FILE--
<?php

use Async\Channel;
use Async\AsyncCancellation;
use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await;
use function Async\delay;

// --- Test 1: recv() cancelled by Future completion ---

$ch = new Channel(0);
$state = new FutureState();
$cancel = new Future($state);

$recv_coroutine = spawn(function() use ($ch, $cancel) {
    try {
        echo "recv: waiting with future cancellation\n";
        $ch->recv($cancel);
        echo "recv: should not reach here\n";
    } catch (AsyncCancellation $e) {
        echo "recv: cancelled - " . $e->getMessage() . "\n";
    }
});

spawn(function() use ($state) {
    delay(10);
    echo "signalling cancel\n";
    $state->complete(null);
});

await($recv_coroutine);

// --- Test 2: send() cancelled by Future completion ---

$ch2 = new Channel(0);
$ch2->sendAsync("fill"); // fill rendezvous slot so send() will block

$state2 = new FutureState();
$cancel2 = new Future($state2);

$send_coroutine = spawn(function() use ($ch2, $cancel2) {
    try {
        echo "send: waiting with future cancellation\n";
        $ch2->send("blocked", $cancel2);
        echo "send: should not reach here\n";
    } catch (AsyncCancellation $e) {
        echo "send: cancelled - " . $e->getMessage() . "\n";
    }
});

spawn(function() use ($state2) {
    delay(10);
    echo "signalling cancel\n";
    $state2->complete(null);
});

await($send_coroutine);

?>
--EXPECT--
recv: waiting with future cancellation
signalling cancel
recv: cancelled - Operation has been cancelled
send: waiting with future cancellation
signalling cancel
send: cancelled - Operation has been cancelled
