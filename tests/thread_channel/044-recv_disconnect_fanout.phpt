--TEST--
ThreadChannel: many workers parked on recv() do not hang when the owner finishes (#162 fan-out)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use function Async\spawn_thread;

// Several workers park on recv(); the owner finishes without close(). All must
// disconnect at shutdown so the process exits (no hang for any number of workers).
$ch = new ThreadChannel();

for ($i = 0; $i < 3; $i++) {
    spawn_thread(function() use ($ch) {
        try { $ch->recv(); echo "worker: unexpected value\n"; }
        catch (\Throwable $e) {}
    });
}

echo "main: end\n";
?>
--EXPECT--
main: end
