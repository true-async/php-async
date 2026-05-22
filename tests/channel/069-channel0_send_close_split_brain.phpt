--TEST--
Channel(0): close while a send() is parked must not leave a value for a later recv()
--DESCRIPTION--
An unbuffered send() copies its value into the rendezvous slot, then parks.
If close() races in before any receiver arrives, the parked send() fails —
and the value must NOT survive for a receiver that connects afterwards.
Otherwise: split-brain — the receiver gets the value, the sender threw.
Regression test for channel_close() not clearing the rendezvous slot.
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\suspend;
use function Async\await;

spawn(function () {
    $ch = new Channel(0);
    $sendOk = null;
    $recvOk = null;

    $sender = spawn(function () use ($ch, &$sendOk) {
        try { $ch->send(42); $sendOk = true; }
        catch (\Throwable $e) { $sendOk = false; }
    });

    // Let the sender run and park inside send() — value now in the slot.
    suspend();

    // Close while the sender is parked, before any receiver exists.
    $ch->close();

    $receiver = spawn(function () use ($ch, &$recvOk) {
        try { $recvOk = $ch->recv(); }
        catch (\Throwable $e) { $recvOk = 'threw'; }
    });

    await($sender);
    await($receiver);

    $consistent = ($sendOk === true  && $recvOk === 42)
               || ($sendOk === false && $recvOk === 'threw');
    echo $consistent
        ? "consistent\n"
        : "SPLIT-BRAIN: send=" . var_export($sendOk, true)
            . " recv=" . var_export($recvOk, true) . "\n";
});
?>
--EXPECT--
consistent
