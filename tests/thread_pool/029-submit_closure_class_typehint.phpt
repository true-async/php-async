--TEST--
ThreadPool: closure with Closure-typed parameter (regression: arg_info[*].type not deep-copied to worker emalloc)
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

    /* The outer closure has a class-name typehint (Closure) as a parameter.
     * After cross-thread transfer the worker dereferences arg_info[0].type
     * → its class-name zend_string → zend_string_tolower_ex (via
     * zend_lookup_class_ex) on call. Before the op_array_to_emalloc
     * deep-copy fix, that zend_string still pointed at the parent's
     * persistent arena, freed by the time the worker ran, and tolower
     * read garbage as length → multi-exabyte _emalloc → memory_limit
     * fatal error → bailout. */
    $futures = [];
    for ($i = 1; $i <= 4; $i++) {
        $futures[] = $pool->submit(
            static function (Closure $cb, int $n): int { return $cb($n); },
            static fn(int $x): int => $x * 10,
            $i
        );
    }
    foreach ($futures as $f) {
        echo await($f), "\n";
    }

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
10
20
30
40
Done
