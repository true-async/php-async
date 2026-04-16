--TEST--
ThreadPool: submit multiple tasks, collect results
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

    $futures = [];
    for ($i = 0; $i < 5; $i++) {
        $futures[] = $pool->submit(fn(int $x) => $x * $x, $i);
    }

    $results = [];
    foreach ($futures as $f) {
        $results[] = await($f);
    }

    echo implode(",", $results) . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
0,1,4,9,16
Done
