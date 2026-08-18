--TEST--
Channel: deadlock timer resets between successful operations
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\delay;

spawn(function () {
    // 1000ms timeout; sender pauses 500ms each iteration — under timeout, must not fire.
    // The gap is far larger than the timeout needs it to be so that the check survives
    // an interpreter slowed down by a memory checker: only the ratio carries the claim,
    // and 500ms of slack per iteration is more than such a run costs.
    $ch = new Channel(0, 1000, 1000, true);
    spawn(function () use ($ch) {
        for ($i = 0; $i < 3; $i++) {
            delay(500);
            $ch->send($i);
        }
    });
    $start = microtime(true);
    for ($i = 0; $i < 3; $i++) {
        $ch->recv();
    }
    $elapsed = (int) ((microtime(true) - $start) * 1000);
    // ~1500ms total; if the timer accumulated it would have fired at 1000ms.
    // The upper bound is loose because a memory checker multiplies the work
    // between the delays, but it is a bound: without one the check passes on
    // any run slow enough, whatever the timer did.
    $ok = ($elapsed >= 1400 && $elapsed < 6000) ? "yes" : "no ($elapsed)";
    echo "no_premature_fire=", $ok, "\n";
    echo "closed=", $ch->isClosed() ? "true" : "false", "\n";
});
?>
--EXPECT--
no_premature_fire=yes
closed=false
