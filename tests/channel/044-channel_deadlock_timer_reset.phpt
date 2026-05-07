--TEST--
Channel: deadlock timer resets between successful operations
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\delay;

spawn(function () {
    // 200ms timeout; sender pauses 100ms each iteration — under timeout, must not fire
    $ch = new Channel(0, 200, 200, true);
    spawn(function () use ($ch) {
        for ($i = 0; $i < 3; $i++) {
            delay(100);
            $ch->send($i);
        }
    });
    $start = microtime(true);
    for ($i = 0; $i < 3; $i++) {
        $ch->recv();
    }
    $elapsed = (int) ((microtime(true) - $start) * 1000);
    // ~300ms total; if timer accumulated it would have fired at 200ms
    $ok = ($elapsed >= 280 && $elapsed < 500) ? "yes" : "no ($elapsed)";
    echo "no_premature_fire=", $ok, "\n";
    echo "closed=", $ch->isClosed() ? "true" : "false", "\n";
});
?>
--EXPECT--
no_premature_fire=yes
closed=false
