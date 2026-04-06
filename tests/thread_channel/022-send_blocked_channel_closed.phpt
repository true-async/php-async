--TEST--
ThreadChannel: send blocks on full buffer, channel closed — sender gets exception
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

// Capacity 1 — second send blocks
$ch = new ThreadChannel(1);

spawn(function() use ($ch) {
    // Thread closes the channel after a short delay
    $thread = spawn_thread(function() use ($ch) {
        $ch->recv(); // take one item to let main proceed
        $ch->close();
        return "closed";
    });

    $ch->send("first"); // fills buffer

    // This send blocks (buffer full), then channel is closed
    try {
        $ch->send("second");
        echo "ERROR: send should have thrown\n";
    } catch (ThreadChannelException $e) {
        echo "Send blocked then closed: " . $e->getMessage() . "\n";
    }

    echo await($thread) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
Send blocked then closed: ThreadChannel is closed
closed
Done
