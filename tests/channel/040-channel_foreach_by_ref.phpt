--TEST--
Channel: foreach by reference is forbidden
--FILE--
<?php

use Async\Channel;
use function Async\spawn;

// Covers channel.c channel_get_iterator() L517-519 by_ref error branch.

spawn(function() {
    $ch = new Channel(3);
    $ch->send(1);
    $ch->send(2);
    $ch->close();

    try {
        foreach ($ch as &$v) {
            echo "should-not: $v\n";
        }
        unset($v);
    } catch (\Error $e) {
        echo "by-ref: ", $e->getMessage(), "\n";
    }
});

?>
--EXPECT--
by-ref: Cannot iterate channel by reference
