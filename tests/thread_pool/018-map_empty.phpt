--TEST--
ThreadPool: map with empty array
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
    $pool = new ThreadPool(2);

    $results = $pool->map([], fn($x) => $x * 2);
    echo "Count: " . count($results) . "\n";
    echo "Type: " . gettype($results) . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
Count: 0
Type: array
Done
