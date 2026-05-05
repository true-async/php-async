--TEST--
ThreadPool: closure with union-of-classes typehint (regression: zend_type_list path)
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

    /* Union types exercise the ZEND_TYPE_HAS_LIST branch in
     * op_array_emalloc_copy_type — distinct from single-name types.
     * zend_type_list itself and each list entry's class-name zend_string
     * must all be reallocated into the worker's emalloc heap, otherwise
     * dereferencing them on type-check reads garbage. */
    $f1 = $pool->submit(
        static function (Closure|ArrayObject $cb): int {
            return $cb instanceof Closure ? $cb(7) : $cb->count();
        },
        static fn(int $x): int => $x * 6
    );
    echo await($f1), "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
42
Done
