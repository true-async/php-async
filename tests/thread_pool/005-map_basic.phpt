--TEST--
ThreadPool: map applies function to all items in parallel
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

    $results = $pool->map([1, 2, 3, 4, 5], fn(int $x) => $x * 2);

    echo implode(",", $results) . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
2,4,6,8,10
Done
