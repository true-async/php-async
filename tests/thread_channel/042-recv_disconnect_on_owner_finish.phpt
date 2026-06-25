--TEST--
ThreadChannel: recv() disconnects instead of hanging when the owner finishes without close() (#162)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use Async\ThreadChannelException;
use function Async\spawn_thread;

// A worker parks on recv(); the owning side (the main script) finishes without
// sending or closing. The worker must wake with a disconnect exception instead
// of keeping the process alive forever.
$ch = new ThreadChannel();

spawn_thread(function() use ($ch) {
    try {
        $ch->recv();
        echo "worker: got a value\n";
    } catch (ThreadChannelException $e) {
        echo "worker: " . $e->getMessage() . "\n";
    }
});

echo "main: end (nothing sent, channel not closed)\n";
?>
--EXPECT--
main: end (nothing sent, channel not closed)
worker: ThreadChannel deadlock: no producers remain to send
