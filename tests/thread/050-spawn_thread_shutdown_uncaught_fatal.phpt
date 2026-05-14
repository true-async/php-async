--TEST--
spawn_thread() - worker threads in flight when an uncaught exception aborts the request
--DESCRIPTION--
Regression test for the shutdown use-after-free: worker OS threads block on a
ThreadChannel; the main coroutine closes the channels and throws an uncaught
exception, so the request tears down abruptly while the workers are unblocking
from recv() and finishing inside async_thread_run. Before the fix the engine
freed the thread event while a worker was still about to hand back its result,
jumping through a dangling notify_parent pointer. Must end with the Fatal
error only — never a segfault.
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use Async\ThreadChannel;

spawn(function() {
    $jobs    = new ThreadChannel(16);
    $results = new ThreadChannel(16);

    for ($t = 0; $t < 3; $t++) {
        spawn_thread(function() use ($jobs, $results) {
            try {
                while (true) {
                    $n = $jobs->recv();
                    $x = 0.0;
                    for ($i = 0; $i < $n; $i++) { $x += sqrt($i); }
                    $results->send($x);
                }
            } catch (\Async\ThreadChannelException) {
                // channel closed during shutdown — exit the worker
            }
        });
    }

    for ($i = 0; $i < 6; $i++) {
        $jobs->send(200000);
    }

    // Abrupt teardown: close channels and blow up with an uncaught exception
    // while the workers are still in flight.
    $jobs->close();
    $results->close();
    throw new \RuntimeException('shutdown trigger');
});
?>
--EXPECTF--
%wFatal error: Uncaught RuntimeException: shutdown trigger in %s:%d
Stack trace:
#0 %s
#1 {main}
  thrown in %s on line %d
