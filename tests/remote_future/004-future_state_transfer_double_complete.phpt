--TEST--
RemoteFuture: FutureState transferred — double complete throws
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $thread = spawn_thread(function() use ($state) {
        $state->complete("first");
        try {
            $state->complete("second");
            return "ERROR: should have thrown";
        } catch (\Throwable $e) {
            return "Caught: " . $e->getMessage();
        }
    });

    echo await($future) . "\n";
    echo await($thread) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
first
Caught: FutureState is already completed
Done
