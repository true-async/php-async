--TEST--
RemoteFuture: multiple coroutines await same remote future
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

    // Two coroutines await the same future
    $c1 = spawn(fn() => "c1:" . await($future));
    $c2 = spawn(fn() => "c2:" . await($future));

    $thread = spawn_thread(function() use ($state) {
        $state->complete("result");
    });

    $results = [await($c1), await($c2)];
    sort($results);
    echo implode("\n", $results) . "\n";

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
c1:result
c2:result
Done
