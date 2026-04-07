--TEST--
RemoteFuture: source thread loses write access after transfer: bug
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
        $state->complete("from thread");
    });

    // Source thread should not be able to complete after transfer
    $state->complete("from main");

    echo await($future) . "\n";
    await($thread);
    echo "Done\n";
});
?>
--EXPECTF--
Fatal error: Uncaught Async\AsyncException: FutureState ownership was transferred to another thread in %s:%d
Stack trace:
%A
