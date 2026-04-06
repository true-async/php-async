--TEST--
ThreadChannel: recv blocks on empty buffer, channel closed from thread — receiver gets exception
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

$ch = new ThreadChannel(4);

spawn(function() use ($ch) {
    // Thread closes the channel without sending anything
    $thread = spawn_thread(function() use ($ch) {
        $ch->close();
        return "closed";
    });

    // recv blocks (empty buffer), then channel is closed
    try {
        $ch->recv();
        echo "ERROR: recv should have thrown\n";
    } catch (ThreadChannelException $e) {
        echo "Recv blocked then closed: " . $e->getMessage() . "\n";
    }

    echo await($thread) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
Recv blocked then closed: ThreadChannel is closed
closed
Done
