--TEST--
ThreadChannel: close from one thread while another is using it
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
    // Thread sends some data then closes
    $thread = spawn_thread(function() use ($ch) {
        $ch->send("before_close");
        $ch->close();
        return "thread_done";
    });

    // Main receives data, then gets exception on closed channel
    echo $ch->recv() . "\n";

    try {
        $ch->recv();
    } catch (ThreadChannelException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    echo await($thread) . "\n";
});
?>
--EXPECT--
before_close
Caught: ThreadChannel is closed
thread_done
