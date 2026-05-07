--TEST--
Channel: deadlock timer disabled when both timeouts are 0
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\delay;

spawn(function () {
    $ch = new Channel(0, 0, 0);
    spawn(function () use ($ch) {
        delay(150);
        $ch->send("hello");
    });
    $v = $ch->recv();
    echo "got=", $v, "\n";
    echo "closed=", $ch->isClosed() ? "true" : "false", "\n";
});
?>
--EXPECT--
got=hello
closed=false
