--TEST--
ThreadPool: map with exception in one item
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

    try {
        $results = $pool->map([1, 2, 0, 4], function(int $x) {
            if ($x === 0) {
                throw new \RuntimeException("division by zero");
            }
            return 10 / $x;
        });
        echo "ERROR: should have thrown\n";
    } catch (\Throwable $e) {
        echo "Caught: " . $e->getMessage() . "\n";
    }

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
Caught: division by zero
Done
