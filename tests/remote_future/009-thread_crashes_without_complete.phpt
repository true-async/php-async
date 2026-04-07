--TEST--
RemoteFuture: thread throws without calling complete — future must not hang
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use Async\RemoteException;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $thread = spawn_thread(function() use ($state) {
        // Thread crashes without calling complete
        throw new \RuntimeException("thread crashed");
    });

    try {
        await($thread);
    } catch (RemoteException $e) {
        echo "Thread crashed: " . $e->getMessage() . "\n";
    }

    // Future was never completed — should not hang
    echo "After thread\n";
    echo "isCompleted: " . ($state->isCompleted() ? "yes" : "no") . "\n";
    $state->ignore();
    echo "Done\n";
});
?>
--EXPECT--
Thread crashed: thread crashed
After thread
isCompleted: no
Done
