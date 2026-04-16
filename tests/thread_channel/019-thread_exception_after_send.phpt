--TEST--
ThreadChannel: thread throws exception after partial send — main recv gets available data
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
        $ch->send("msg1");
        $ch->send("msg2");
        throw new \RuntimeException("thread crashed");
    });

    // Receive what was sent before the crash
    echo $ch->recv() . "\n";
    echo $ch->recv() . "\n";

    // Thread threw — await should propagate the exception
    try {
        await($thread);
    } catch (RemoteException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    echo "Done\n";
});
?>
--EXPECT--
msg1
msg2
Caught: thread crashed
Done
