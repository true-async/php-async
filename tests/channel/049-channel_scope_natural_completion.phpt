--TEST--
Channel: scope ends naturally (all coroutines done) — channel survives until owner zval dies
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

// Channel created at top-level: owner is main_scope. We simply verify that
// nothing crashes during normal lifetime + script shutdown (which disposes
// main_scope and fires the channel's scope-close callback).
$ch = new Channel(0, 0, 0);

$task = spawn(function () use ($ch) {
    delay(20);
    $ch->send("payload");
});

$value = $ch->recv();
echo "got=", $value, "\n";

await($task);
echo "done\n";
?>
--EXPECT--
got=payload
done
