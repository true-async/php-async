--TEST--
Channel: recv completes immediately, Future cancellation token marked as used
--FILE--
<?php

use Async\Channel;
use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await;

$ch = new Channel(1);
$ch->sendAsync("value");

$state = new FutureState();
$cancel = new Future($state);

$coroutine = spawn(function() use ($ch, $cancel) {
    $v = $ch->recv($cancel);
    echo "Received: $v\n";
});

await($coroutine);

?>
--EXPECT--
Received: value
