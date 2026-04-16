--TEST--
ThreadPool: map() with heterogeneous return types per item
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

    $results = $pool->map(
        ['a', 'b', 'c', 'd'],
        fn($item) => match ($item) {
            'a' => 1,
            'b' => "two",
            'c' => [3, 3, 3],
            'd' => null,
        }
    );

    echo json_encode($results) . "\n";
    $pool->close();
});
?>
--EXPECT--
[1,"two",[3,3,3],null]
