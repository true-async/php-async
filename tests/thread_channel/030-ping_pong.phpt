--TEST--
ThreadChannel: ping-pong between main and thread using two channels
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

$to_thread = new ThreadChannel(1);
$to_main = new ThreadChannel(1);

spawn(function() use ($to_thread, $to_main) {
    $thread = spawn_thread(function() use ($to_thread, $to_main) {
        for ($i = 0; $i < 5; $i++) {
            $msg = $to_thread->recv();
            $to_main->send("pong:$msg");
        }
    });

    for ($i = 0; $i < 5; $i++) {
        $to_thread->send("ping$i");
        echo $to_main->recv() . "\n";
    }

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
pong:ping0
pong:ping1
pong:ping2
pong:ping3
pong:ping4
Done
