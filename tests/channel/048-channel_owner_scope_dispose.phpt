--TEST--
Channel: closes with reason "scope_disposed" when owner scope is disposed
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
$reason = null;

$scope->spawn(function () use (&$ch, &$reason) {
    $ch = new Channel(0, 0, 0);
    try {
        $ch->recv();
        echo "FAIL: recv returned\n";
    } catch (ChannelException $e) {
        $reason = $e->reason->name;
    }
});

spawn(function () use ($scope) {
    delay(50);
    $scope->dispose();
});

await(spawn(function () { delay(150); }));

echo "reason=", $reason, "\n";
echo "closed=", $ch && $ch->isClosed() ? "true" : "false", "\n";
?>
--EXPECT--
reason=SCOPE_DISPOSED
closed=true
