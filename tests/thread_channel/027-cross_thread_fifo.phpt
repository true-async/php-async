--TEST--
ThreadChannel: FIFO order preserved in cross-thread communication
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

$ch = new ThreadChannel(16);

spawn(function() use ($ch) {
    $thread = spawn_thread(function() use ($ch) {
        for ($i = 0; $i < 10; $i++) {
            $ch->send($i);
        }
    });

    $results = [];
    for ($i = 0; $i < 10; $i++) {
        $results[] = $ch->recv();
    }

    await($thread);

    // Must be in exact order, not just sorted
    echo implode(",", $results) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
0,1,2,3,4,5,6,7,8,9
Done
