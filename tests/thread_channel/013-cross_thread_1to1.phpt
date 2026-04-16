--TEST--
ThreadChannel: cross-thread 1 sender thread → 1 receiver (main)
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

$ch = new ThreadChannel(4);

spawn(function() use ($ch) {
    // Sender thread
    $thread = spawn_thread(function() use ($ch) {
        $ch->send("from_thread_1");
        $ch->send("from_thread_2");
        $ch->send("from_thread_3");
    });

    // Receive in main thread
    echo $ch->recv() . "\n";
    echo $ch->recv() . "\n";
    echo $ch->recv() . "\n";

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
from_thread_1
from_thread_2
from_thread_3
Done
