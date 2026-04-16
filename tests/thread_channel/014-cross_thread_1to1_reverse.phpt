--TEST--
ThreadChannel: cross-thread 1 sender (main) → 1 receiver thread
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
    // Receiver thread
    $thread = spawn_thread(function() use ($ch) {
        $results = [];
        $results[] = $ch->recv();
        $results[] = $ch->recv();
        $results[] = $ch->recv();
        return $results;
    });

    // Send from main thread
    $ch->send(10);
    $ch->send(20);
    $ch->send(30);

    $results = await($thread);
    echo implode(",", $results) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
10,20,30
Done
