--TEST--
RemoteFuture: FutureState cannot be transferred to multiple threads
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

    $t1 = spawn_thread(function() use ($state) {
        $state->complete("from t1");
    });

    // Second transfer should throw
    try {
        $t2 = spawn_thread(function() use ($state) {
            $state->complete("from t2");
        });
        echo "ERROR: should have thrown\n";
    } catch (\Throwable $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    echo await($future) . "\n";
    await($t1);
    echo "Done\n";
});
?>
--EXPECT--
Caught: FutureState cannot be transferred to multiple threads
from t1
Done
