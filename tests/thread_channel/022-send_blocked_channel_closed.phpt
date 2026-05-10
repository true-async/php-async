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

// Capacity 1 — second send blocks on full buffer
$ch = new ThreadChannel(1);

spawn(function() use ($ch) {
    // Thread just closes the channel. Whether close fires before/after
    // the buffer fills, or while the second send is parked, the sender
    // observes the same outcome: a ThreadChannelException somewhere
    // in the send sequence below. This avoids the recv-vs-close race
    // that makes the send("second") wake-up vs close-broadcast order
    // platform-dependent (see thread_channel/023 for the recv mirror).
    $thread = spawn_thread(function() use ($ch) {
        $ch->close();
        return "closed";
    });

    try {
        $ch->send("first");   // may succeed (buffer slot free) or
                              // throw if thread closed first
        $ch->send("second");  // buffer full → blocks → close wakes
                              // it with an exception
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
