--TEST--
ThreadPool: concurrent submits from multiple coroutines
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
    $pool = new ThreadPool(3);

    $coros = [];
    for ($i = 0; $i < 8; $i++) {
        $n = $i;
        $coros[] = spawn(function() use ($pool, $n) {
            return await($pool->submit(fn() => $n * 10));
        });
    }

    $results = [];
    foreach ($coros as $c) {
        $results[] = await($c);
    }

    sort($results);
    echo implode(',', $results) . "\n";

    $pool->close();
});
?>
--EXPECT--
0,10,20,30,40,50,60,70
