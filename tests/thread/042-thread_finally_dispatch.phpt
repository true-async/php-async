--TEST--
Thread: finally() rejects non-callable; registration on a running thread fires at dtor
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn_thread;
use function Async\await;

// Covers thread.c:
//   - METHOD(finally) non-callable error at L2309-2311
//   - thread_object_dtor() drain-time finally dispatch at L2117-2145
//     (with the fix: passes ZEND_ASYNC_MAIN_SCOPE to
//     async_call_finally_handlers() instead of NULL, and releases the
//     handlers safely if the async subsystem is already off)

use function Async\spawn;
use function Async\suspend;

spawn(function() {
    // 1. Non-callable argument rejected.
    $t = spawn_thread(function() { return 1; });
    try {
        $t->finally("definitely not a function");
    } catch (\Async\AsyncException $e) {
        echo "non-callable: ", $e->getMessage(), "\n";
    }
    await($t);

    // 2. Register finally() on a still-running thread, then release the
    //    variable inside a live coroutine. The handler must fire without
    //    the old NULL-scope crash at dtor time.
    (function() {
        $t2 = spawn_thread(function() {
            for ($i = 0; $i < 50000; $i++) { $x = $i * 2; }
            return 'done';
        });
        $t2->finally(function($thread) {
            echo "finally fired, completed=", $thread->isCompleted() ? "T" : "F", "\n";
        });
        await($t2);
    })();

    // Give the dtor-time finally spawn a chance to run.
    suspend();
    suspend();

    echo "end\n";
});

?>
--EXPECT--
non-callable: Argument #1 ($callback) must be callable
finally fired, completed=T
end
