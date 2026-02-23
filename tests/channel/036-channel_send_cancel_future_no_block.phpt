--TEST--
Channel: send completes immediately, Future cancellation token marked as used
--FILE--
<?php

use Async\Channel;
use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await;

$ch = new Channel(1);

$state = new FutureState();
$cancel = new Future($state);

$coroutine = spawn(function() use ($ch, $cancel) {
    $ch->send("value", $cancel);
    echo "Sent\n";
});

await($coroutine);

?>
--EXPECT--
Sent
