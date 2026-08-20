--TEST--
ThreadPool: closure with class-name return type (regression: arg_info[-1].type not deep-copied)
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
