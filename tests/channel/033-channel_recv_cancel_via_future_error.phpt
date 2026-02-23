--TEST--
Channel: recv cancelled via Future that resolves with an exception
--FILE--
<?php

use Async\Channel;
use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$ch = new Channel(0);
$state = new FutureState();
$state->ignore();
$cancel = new Future($state);

$coroutine = spawn(function() use ($ch, $cancel) {
    try {
        echo "Waiting for recv with future cancellation\n";
        $ch->recv($cancel);
        echo "Should not reach here\n";
    } catch (\RuntimeException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }
    echo "Done\n";
});

spawn(function() use ($state) {
    delay(10);
    echo "Signalling cancel with error\n";
    $state->error(new \RuntimeException("Custom cancel reason"));
});

await($coroutine);

?>
--EXPECT--
Waiting for recv with future cancellation
Signalling cancel with error
Caught: Custom cancel reason
Done
