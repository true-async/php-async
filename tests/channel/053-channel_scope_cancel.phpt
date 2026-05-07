--TEST--
Channel: scope cancel() also fires the close-callback
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$scope = new Scope();
$reason = null;

$scope->spawn(function () use (&$reason) {
    $ch = new Channel(0, 0, 0);
    try {
        $ch->recv();
    } catch (ChannelException $e) {
        $reason = $e->reason->name;
    } catch (\Throwable $e) {
        $reason = "OTHER:" . get_class($e);
    }
});

spawn(function () use ($scope) { delay(40); $scope->cancel(); });

await(spawn(function () { delay(120); }));

echo "reason=", $reason, "\n";
?>
--EXPECT--
reason=SCOPE_DISPOSED
