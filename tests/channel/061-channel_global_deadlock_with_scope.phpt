--TEST--
Channel: global deadlock detector resolves channels in a custom scope, scope still cleans up
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\await;

$scope = new Scope();
$reason = null;

// Soft timer (default) inside a custom scope. With nothing else alive,
// the global resolver should fire and close the channel with DEADLOCK
// (not SCOPE_DISPOSED — the scope didn't dispose, the resolver did).
$task = $scope->spawn(function () use (&$reason) {
    $ch = new Channel(0, 5000, 5000, false);
    try {
        $ch->recv();
    } catch (ChannelException $e) {
        $reason = $e->reason->name;
    }
});

await($task);
echo "reason=", $reason, "\n";

// Scope should still be usable for further work.
$scope->spawn(function () { /* short task */ });
echo "scope_alive=", $scope->isClosed() ? "false" : "true", "\n";
?>
--EXPECT--
reason=DEADLOCK
scope_alive=true
