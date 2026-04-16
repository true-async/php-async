--TEST--
ThreadChannel: multiple coroutines in main thread wait on recv from same channel
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

$ch = new ThreadChannel(8);

spawn(function() use ($ch) {
    // Two coroutines waiting on recv
    $c1 = spawn(function() use ($ch) {
        return "c1:" . $ch->recv();
    });

    $c2 = spawn(function() use ($ch) {
        return "c2:" . $ch->recv();
    });

    // Thread sends two values — each coroutine gets one
    $thread = spawn_thread(function() use ($ch) {
        $ch->send("hello");
        $ch->send("world");
    });

    $results = [await($c1), await($c2)];
    sort($results);
    echo implode("\n", $results) . "\n";

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
c1:hello
c2:world
Done
