--TEST--
ThreadChannel: recv() disconnects when the producer coroutine finishes without close() (#162)
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

// The producer is a coroutine (not the main script): it creates the channel,
// spawns a worker that parks on recv(), then finishes — dropping its only
// reference. This exercises the at-park path (the producer can drop before the
// worker parks), unlike the top-level case which closes via dispose.
spawn(function() {
    $ch = new ThreadChannel();
    spawn_thread(function() use ($ch) {
        try {
            $ch->recv();
            echo "worker: got a value\n";
        } catch (ThreadChannelException $e) {
            echo "worker: " . $e->getMessage() . "\n";
        }
    });
    echo "producer: done (no send, no close)\n";
});

echo "main: done\n";
?>
--EXPECT--
main: done
producer: done (no send, no close)
worker: ThreadChannel is closed
