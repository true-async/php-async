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
    // Two coroutines waiting on recv — the race between them determines
    // which one receives which value, so the test collects the payloads
    // into an order-independent array and sorts it before printing.
    $c1 = spawn(function() use ($ch) {
        return $ch->recv();
    });

    $c2 = spawn(function() use ($ch) {
        return $ch->recv();
    });

    // Thread sends two values — each coroutine gets one
    $thread = spawn_thread(function() use ($ch) {
        $ch->send("hello");
        $ch->send("world");
    });

    $results = [await($c1), await($c2)];
    sort($results);
    foreach ($results as $i => $v) {
        echo $i . ":" . $v . "\n";
    }

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
0:hello
1:world
Done
