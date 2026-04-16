--TEST--
ThreadChannel: thread blocks on recv, main closes channel — thread gets exception
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use Async\RemoteException;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

$ch = new ThreadChannel(4);

spawn(function() use ($ch) {
    $thread = spawn_thread(function() use ($ch) {
        // This recv will block — nothing in buffer, main will close
        try {
            $ch->recv();
            return "ERROR: recv should have thrown";
        } catch (\Throwable $e) {
            return "Caught in thread: " . $e->getMessage();
        }
    });

    // Close channel while thread is waiting on recv
    $ch->close();

    echo await($thread) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
Caught in thread: ThreadChannel is closed
Done
