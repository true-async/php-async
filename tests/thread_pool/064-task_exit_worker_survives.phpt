--TEST--
ThreadPool: a worker survives exit()/throw mixed with normal tasks (single worker, ordered)
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
    $pool = new ThreadPool(1);

    $t1 = $pool->submit(function() { exit(1); });
    $t2 = $pool->submit(function() { return "normal-1"; });
    $t3 = $pool->submit(function() { throw new RuntimeException("boom"); });
    $t4 = $pool->submit(function() { exit(2); });
    $t5 = $pool->submit(function() { return 7 * 7; });

    var_dump(await($t1));
    var_dump(await($t2));
    try {
        await($t3);
    } catch (\RuntimeException $e) {
        echo "t3: ", $e->getMessage(), "\n";
    }
    var_dump(await($t4));
    var_dump(await($t5));

    $pool->close();
});
?>
--EXPECT--
NULL
string(8) "normal-1"
t3: boom
NULL
int(49)
