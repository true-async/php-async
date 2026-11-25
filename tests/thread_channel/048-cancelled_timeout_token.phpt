--TEST--
ThreadChannel: a cancelled Timeout as the token is a disarmed timer for send() and recv()
--FILE--
<?php

use Async\ThreadChannel;
use function Async\await;
use function Async\spawn;
use function Async\timeout;

function disarmed(): Async\Timeout
{
    $timeout = timeout(50);
    $timeout->cancel();

    return $timeout;
}

await(spawn(function () {
    $channel = new ThreadChannel(1);
    $channel->send(7, disarmed());
    var_dump($channel->recv(disarmed()));
}));

echo "end\n";

?>
--EXPECT--
int(7)
end
