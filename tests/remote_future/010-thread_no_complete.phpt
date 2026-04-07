--TEST--
RemoteFuture: thread returns without calling complete — no hang
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
        // Thread finishes without calling complete
        return "thread done";
    });

    echo await($thread) . "\n";

    // Future was never completed — cleanup should work
    $state->ignore();
    echo "Done\n";
});
?>
--EXPECT--
thread done
Done
