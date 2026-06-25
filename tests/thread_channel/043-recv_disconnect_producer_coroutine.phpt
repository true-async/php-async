--TEST--
ThreadChannel: recv() does not hang when the producer coroutine finishes without close() (#162)
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

// The producer is a coroutine that creates the channel, spawns a worker parked
// on recv(), then finishes. The worker must disconnect at shutdown (no hang).
spawn(function() {
    $ch = new ThreadChannel();
    spawn_thread(function() use ($ch) {
        try { $ch->recv(); echo "worker: unexpected value\n"; }
        catch (\Throwable $e) {}
    });
    echo "producer: done\n";
});

echo "main: done\n";
?>
--EXPECT--
main: done
producer: done
