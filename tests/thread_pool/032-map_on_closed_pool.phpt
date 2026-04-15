--TEST--
ThreadPool: map() on a closed pool throws ThreadPoolException
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use Async\ThreadPoolException;
use function Async\spawn;

// Covers thread_pool.c METHOD(map) L515-522: pool-not-initialized and
// pool-closed guards. Existing test 007 covers the same guards for
// submit(); this extends coverage to map().

spawn(function() {
    $pool = new ThreadPool(2);
    $pool->close();

    try {
        $pool->map([1, 2, 3], fn($x) => $x * 2);
        echo "ERROR: should have thrown\n";
    } catch (ThreadPoolException $e) {
        echo "caught: ", $e->getMessage(), "\n";
    }
});

?>
--EXPECT--
caught: ThreadPool is closed
