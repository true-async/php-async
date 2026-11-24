--TEST--
A cancelled Timeout passed as a cancellation token is a disarmed timer: the operation runs as without a token
--FILE--
<?php

use Async\Channel;
use Async\Future;
use Async\FutureState;
use Async\Scope;
use function Async\await;
use function Async\await_all;
use function Async\delay;
use function Async\spawn;
use function Async\timeout;

function disarmed(): Async\Timeout
{
    $timeout = timeout(50);
    $timeout->cancel();

    return $timeout;
}

await(spawn(function () {
    echo "await: ", await(spawn(fn() => 1), disarmed()), "\n";

    echo "await_all: ", await_all([spawn(fn() => 2)], disarmed())[0][0], "\n";

    $channel = new Channel(1);
    spawn(function () use ($channel) {
        delay(10);
        $channel->send(3);
    });
    echo "Channel::recv: ", $channel->recv(disarmed()), "\n";

    $scope = new Scope();
    $scope->spawn(fn() => delay(10));
    $scope->awaitCompletion(disarmed());
    echo "Scope::awaitCompletion: done\n";

    $state = new FutureState();
    $future = new Future($state);
    spawn(function () use ($state) {
        delay(10);
        $state->complete(5);
    });
    echo "Future::await: ", $future->await(disarmed()), "\n";
}));

echo "end\n";

?>
--EXPECT--
await: 1
await_all: 2
Channel::recv: 3
Scope::awaitCompletion: done
Future::await: 5
end
