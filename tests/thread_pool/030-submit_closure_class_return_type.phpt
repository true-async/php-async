--TEST--
ThreadPool: closure with class-name return type (regression: arg_info[-1].type not deep-copied)
--XFAIL--
Returning a Closure from a worker thread is not yet implemented.
The worker creates a per-result closure snapshot in closure_transfer_obj
(TRANSFER branch), but the result-closure's persistent shell ends up
referencing memory in the task snapshot arena, which the worker frees
in thread_pool_worker_handler before the main thread loads the result.
This causes a heap-use-after-free in async_thread_create_closure when
iterating bound_vars on the loading side. Proper support requires the
result-closure snapshot to own its own arena and outlive the task
snapshot until the loading thread consumes it.
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

spawn(function() {
    $pool = new ThreadPool(2);

    /* Return-type slot lives at arg_info[-1] when ZEND_ACC_HAS_RETURN_TYPE
     * is set. op_array_to_emalloc walks num_args+1 slots in that case;
     * the type deep-copy must cover the slot too, otherwise the worker's
     * ZEND_VERIFY_RETURN_TYPE on the first return tolowers garbage. */
    $future = $pool->submit(
        static function (int $n): Closure {
            $captured = $n;
            return static fn() => $captured * 2;
        },
        21
    );
    $result = await($future);
    echo $result(), "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
42
Done
