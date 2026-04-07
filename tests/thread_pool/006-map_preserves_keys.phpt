--TEST--
ThreadPool: map preserves array keys and order
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

    $results = $pool->map(
        ['a' => 'hello', 'b' => 'world'],
        fn(string $s) => strtoupper($s)
    );

    foreach ($results as $k => $v) {
        echo "$k: $v\n";
    }

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
a: HELLO
b: WORLD
Done
