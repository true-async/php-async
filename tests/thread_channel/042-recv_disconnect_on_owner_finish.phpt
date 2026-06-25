--TEST--
ThreadChannel: recv() does not hang shutdown when the owner finishes without close() (#162)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use function Async\spawn_thread;

// Worker parks on recv(); the owner (main) finishes without sending or closing.
// The worker must disconnect at shutdown so the process exits (no hang). The
// worker stays silent on disconnect: a hang fails via timeout, an unexpected
// value fails via extra output.
$ch = new ThreadChannel();

spawn_thread(function() use ($ch) {
    try { $ch->recv(); echo "worker: unexpected value\n"; }
    catch (\Throwable $e) {}
});

echo "main: end\n";
?>
--EXPECT--
main: end
