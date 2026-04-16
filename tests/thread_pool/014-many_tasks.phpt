--TEST--
ThreadPool: many tasks stress test
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
    $pool = new ThreadPool(4);
    $count = 100;

    $futures = [];
    for ($i = 0; $i < $count; $i++) {
        $futures[] = $pool->submit(fn(int $x) => $x * $x, $i);
    }

    $sum = 0;
    foreach ($futures as $f) {
        $sum += await($f);
    }

    $expected = 0;
    for ($i = 0; $i < $count; $i++) {
        $expected += $i * $i;
    }

    echo "Sum: $sum\n";
    echo "Expected: $expected\n";
    echo "Match: " . ($sum === $expected ? "yes" : "no") . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
Sum: 328350
Expected: 328350
Match: yes
Done
