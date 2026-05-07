--TEST--
Channel: outer var keeps channel alive past scope death — operations throw, no UAF
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
    $ch = new Channel(2, 0, 0);
    $ch->send("a");
    $ch->send("b");
    delay(200);
});

spawn(function () use ($scope) { delay(40); $scope->dispose(); });

await(spawn(function () { delay(120); }));

// Scope dead. $ch still alive in this var. Touching it must not UAF.
echo "isClosed=", $ch->isClosed() ? "true" : "false", "\n";

try {
    $ch->send("c");
    echo "FAIL: send returned\n";
} catch (ChannelException $e) {
    echo "send.reason=", $e->reason->name, "\n";
}

// Buffered data is preserved through close — recv drains it before throwing.
echo "drain1=", $ch->recv(), "\n";
echo "drain2=", $ch->recv(), "\n";
try {
    $ch->recv();
    echo "FAIL: recv returned on empty\n";
} catch (ChannelException $e) {
    echo "empty.reason=", $e->reason->name, "\n";
}

// Explicit close on already-closed: idempotent.
$ch->close();
echo "second_close_ok\n";

// Drop last reference — must not crash.
$ch = null;
echo "released\n";
?>
--EXPECT--
isClosed=true
send.reason=SCOPE_DISPOSED
drain1=a
drain2=b
empty.reason=SCOPE_DISPOSED
second_close_ok
released
