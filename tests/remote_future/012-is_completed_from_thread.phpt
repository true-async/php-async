--TEST--
RemoteFuture: isCompleted() from destination thread
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
        $before = $state->isCompleted();
        $state->complete(42);
        $after = $state->isCompleted();
        return [$before, $after];
    });

    $result = await($thread);
    echo "Before: " . ($result[0] ? "yes" : "no") . "\n";
    echo "After: " . ($result[1] ? "yes" : "no") . "\n";
    echo "Value: " . await($future) . "\n";
    echo "Done\n";
});
?>
--EXPECT--
Before: no
After: yes
Value: 42
Done
