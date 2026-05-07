--TEST--
Channel: blocked sender (full buffer) when owner scope disposes — sender wakes with SCOPE_DISPOSED
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
    $ch = new Channel(1, 0, 0);
    $ch->send("first");
    try {
        $ch->send("second"); // blocks: buffer full
        $reason = "FAIL_RETURNED";
    } catch (ChannelException $e) {
        $reason = $e->reason->name;
    } catch (\Throwable $e) {
        $reason = "OTHER:" . get_class($e);
    }
});

spawn(function () use ($scope) { delay(40); $scope->dispose(); });

await(spawn(function () { delay(120); }));

echo "reason=", $reason, "\n";
?>
--EXPECT--
reason=SCOPE_DISPOSED
