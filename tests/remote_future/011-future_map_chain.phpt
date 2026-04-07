--TEST--
RemoteFuture: Future.map() chain works with remote future
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

    $mapped = $future->map(fn($v) => strtoupper($v));

    $thread = spawn_thread(function() use ($state) {
        $state->complete("hello world");
    });

    echo await($mapped) . "\n";
    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
HELLO WORLD
Done
