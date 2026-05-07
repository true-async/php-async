--TEST--
Channel: deadlock timeout wakes all blocked waiters with reason
--FILE--
<?php

use Async\Channel;
use Async\ChannelException;
use function Async\spawn;
use function Async\await_all;

spawn(function () {
    $ch = new Channel(0, 200, 0, true);

    $tasks = [];
    for ($i = 0; $i < 3; $i++) {
        $tasks[] = spawn(function () use ($ch, $i) {
            try {
                $ch->recv();
                return "FAIL-$i";
            } catch (ChannelException $e) {
                return "$i={$e->reason->name}";
            }
        });
    }

    [$results] = await_all($tasks, fillNull: true);
    sort($results);
    foreach ($results as $r) {
        echo $r, "\n";
    }
});
?>
--EXPECT--
0=NO_PRODUCERS
1=NO_PRODUCERS
2=NO_PRODUCERS
