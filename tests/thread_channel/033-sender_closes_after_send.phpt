--TEST--
ThreadChannel: thread sends data then closes — main drains remaining
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use Async\ThreadChannelException;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

$ch = new ThreadChannel(8);

spawn(function() use ($ch) {
    $thread = spawn_thread(function() use ($ch) {
        $ch->send("a");
        $ch->send("b");
        $ch->send("c");
        $ch->close();
    });

    await($thread);

    // Channel is closed but has data — drain it
    $results = [];
    while (!$ch->isEmpty()) {
        $results[] = $ch->recv();
    }

    echo implode(",", $results) . "\n";

    // Next recv should throw
    try {
        $ch->recv();
    } catch (ThreadChannelException $e) {
        echo "After drain: " . $e->getMessage() . "\n";
    }

    echo "Done\n";
});
?>
--EXPECT--
a,b,c
After drain: ThreadChannel is closed
Done
