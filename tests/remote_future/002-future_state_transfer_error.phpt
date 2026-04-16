--TEST--
RemoteFuture: FutureState transferred to thread, error() delivers exception
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
        $state->error(new \RuntimeException("thread error"));
    });

    try {
        await($future);
        echo "ERROR: should have thrown\n";
    } catch (\RuntimeException $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
Caught: thread error
Done
