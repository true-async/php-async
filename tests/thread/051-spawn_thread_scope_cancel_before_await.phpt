--TEST--
spawn_thread() - scope cancelled before the thread is awaited (parent-detached handoff)
--DESCRIPTION--
Regression test for the parent-detached branch of the thread result handoff.
A thread is spawned inside a Scope and the scope is cancelled before anyone
awaits the handle, so the awaiting side is gone by the time the worker
finishes. The worker must notice the event was detached and release its own
transferred result instead of writing into freed memory.
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\Scope;

spawn(function() {
    for ($round = 0; $round < 5; $round++) {
        $scope = new Scope();
        $thread = $scope->spawn(function() {
            $x = 0.0;
            for ($i = 0; $i < 400000; $i++) { $x += sqrt($i); }
            return ['x' => $x, 'buf' => str_repeat('w', 128)];
        });

        // Cancel before awaiting: the awaiter never collects the result.
        $scope->cancel();

        try {
            await($thread);
        } catch (\Throwable) {
            // cancellation surfaces here — expected
        }
    }

    echo "survived scope cancel\n";
});
?>
--EXPECT--
survived scope cancel
