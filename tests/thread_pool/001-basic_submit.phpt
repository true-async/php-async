--TEST--
ThreadPool: basic submit and await result
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

    $future = $pool->submit(fn() => 42);
    echo await($future) . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
42
Done
