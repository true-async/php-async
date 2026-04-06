--TEST--
ThreadChannel: thread throws exception before any send — main must not hang
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use Async\ThreadChannelException;
use Async\RemoteException;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

$ch = new ThreadChannel(4);

spawn(function() use ($ch) {
    $thread = spawn_thread(function() use ($ch) {
        throw new \RuntimeException("instant crash");
    });

    // Thread crashed without sending anything.
    // await the thread first to know it failed, then close channel.
    try {
        await($thread);
    } catch (RemoteException $e) {
        echo "Thread failed: " . $e->getMessage() . "\n";
        $ch->close();
    }

    // recv on closed channel should throw
    try {
        $ch->recv();
    } catch (ThreadChannelException $e) {
        echo "Recv: " . $e->getMessage() . "\n";
    }

    echo "Done\n";
});
?>
--EXPECT--
Thread failed: instant crash
Recv: ThreadChannel is closed
Done
