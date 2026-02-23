--TEST--
Channel: recv throws immediately when Future cancellation token is already rejected
--FILE--
<?php

use Async\Channel;
use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await;

$ch = new Channel(0);

$state = new FutureState();
$state->error(new \RuntimeException("Already cancelled"));
$cancel = new Future($state);

$coroutine = spawn(function() use ($ch, $cancel) {
    try {
        $ch->recv($cancel);
        echo "Should not reach here\n";
    } catch (\RuntimeException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
    echo "Done\n";
});

await($coroutine);

?>
--EXPECT--
Caught: Already cancelled
Done
