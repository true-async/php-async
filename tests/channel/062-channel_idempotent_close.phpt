--TEST--
Channel: explicit close() before scope dispose — first reason wins, no double-close
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$scope = new Scope();
$ch = null;

$scope->spawn(function () use (&$ch) {
    $ch = new Channel(0, 0, 0);
    delay(50);
    $ch->close();    // explicit close first — reason EXPLICIT
    delay(200);      // then scope gets disposed below — must NOT overwrite reason
});

spawn(function () use ($scope) { delay(120); $scope->dispose(); });

await(spawn(function () { delay(200); }));

try {
    $ch->send(1);
    echo "FAIL: send returned\n";
} catch (ChannelException $e) {
    echo "reason=", $e->reason->name, "\n";
}
?>
--EXPECT--
reason=EXPLICIT
