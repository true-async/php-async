--TEST--
ThreadPool: pool goes out of scope without explicit close (quiesce regression)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;

spawn(function() {
    $pool = new ThreadPool(3);
    echo "created\n";
    // No close(), no submit() — relies on GC + reactor quiesce to
    // drain the worker threads before module shutdown.
});
echo "after spawn\n";
?>
--EXPECT--
after spawn
created
