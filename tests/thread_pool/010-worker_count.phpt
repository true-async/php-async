--TEST--
ThreadPool: getWorkerCount returns correct number
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
    $pool = new ThreadPool(4);
    echo "Workers: " . $pool->getWorkerCount() . "\n";
    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
Workers: 4
Done
