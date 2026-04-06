--TEST--
ThreadChannel: thread object goes out of scope — channel still works, no leak
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
    // Thread reference goes out of inner scope
    $result = (function() use ($ch) {
        $thread = spawn_thread(function() use ($ch) {
            $ch->send("data1");
            $ch->send("data2");
        });
        await($thread);
        // $thread goes out of scope here
    })();

    // Channel should still have data
    echo $ch->recv() . "\n";
    echo $ch->recv() . "\n";
    echo "Done\n";
});
?>
--EXPECT--
data1
data2
Done
