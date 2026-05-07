--TEST--
Channel: producer in scope A, consumer in scope B, channel owned by A — A dispose closes recv in B
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use Async\Scope;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$producer_scope = new Scope();
$consumer_scope = new Scope();

$ch = null;
$consumer_reason = null;

// Producer creates and owns the channel.
$producer_scope->spawn(function () use (&$ch) {
    $ch = new Channel(0, 0, 0);
    delay(300); // hang around so the channel stays alive
});

// Wait for $ch to exist, then consumer (in a different scope) blocks on recv.
spawn(function () use (&$ch, $consumer_scope, &$consumer_reason) {
    delay(20);
    $consumer_scope->spawn(function () use (&$ch, &$consumer_reason) {
        try {
            $ch->recv();
            $consumer_reason = "FAIL_RETURNED";
        } catch (ChannelException $e) {
            $consumer_reason = $e->reason->name;
        }
    });
});

// Kill the producer scope while consumer is blocked on its channel.
spawn(function () use ($producer_scope) { delay(80); $producer_scope->dispose(); });

await(spawn(function () { delay(200); }));

echo "consumer_reason=", $consumer_reason, "\n";
echo "ch_closed=", $ch->isClosed() ? "true" : "false", "\n";
?>
--EXPECT--
consumer_reason=SCOPE_DISPOSED
ch_closed=true
