--TEST--
ThreadPool: submit with arguments
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

    $future = $pool->submit(fn(int $a, int $b) => $a + $b, 10, 20);
    echo await($future) . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
30
Done
