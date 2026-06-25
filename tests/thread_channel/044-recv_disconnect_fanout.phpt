--TEST--
ThreadChannel: multiple workers parked on recv() all disconnect when the owner finishes (#162 fan-out)
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

// Two workers park on recv(); the owner (main) finishes without sending or
// closing. Both must disconnect, not hang the process.
$ch = new ThreadChannel();

for ($i = 0; $i < 2; $i++) {
    spawn_thread(function() use ($ch, $i) {
        try { $ch->recv(); echo "w$i: got value\n"; }
        catch (ThreadChannelException $e) { echo "w$i: disconnected\n"; }
    });
}

echo "main: end\n";
?>
--EXPECTF--
main: end
w%d: disconnected
w%d: disconnected
