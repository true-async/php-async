--TEST--
spawn_thread() - threads still in flight when the request ends gracefully
--DESCRIPTION--
Regression test for the shutdown handoff: worker OS threads are spawned and
never awaited, so they are still running inside async_thread_run when the
spawning coroutine returns and the request shuts down. The reactor must wait
for the workers and the workers must hand back (or release) their results
without touching freed memory. Mixes value-returning and throwing workers so
both handoff branches run during teardown.
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;

spawn(function() {
    for ($t = 0; $t < 6; $t++) {
        $throws = ($t % 2 === 1);
        // Deliberately not awaited.
        spawn_thread(function() use ($throws) {
            $x = 0.0;
            for ($i = 0; $i < 300000; $i++) { $x += sqrt($i); }
            if ($throws) {
                throw new \RuntimeException('worker boom');
            }
            return ['x' => $x, 'buf' => str_repeat('q', 96)];
        });
    }

    echo "spawned 6 detached threads\n";
});
?>
--EXPECT--
spawned 6 detached threads
