--TEST--
ThreadPool: exit()/throw mixed with normal tasks: an exit() rejects its task and the pool keeps serving (single worker, ordered)
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

    $exited = function ($future) {
        try {
            await($future);
        } catch (Async\ThreadTransferException $e) {
            echo get_class($e), ": ", $e->getMessage(), "\n";
        }
    };

    $exited($t1);
    var_dump(await($t2));
    try {
        await($t3);
    } catch (\RuntimeException $e) {
        echo "t3: ", $e->getMessage(), "\n";
    }
    $exited($t4);
    var_dump(await($t5));

    $pool->close();
});
?>
--EXPECT--
Async\ThreadTransferException: ThreadPool worker terminated via exit()
string(8) "normal-1"
t3: boom
Async\ThreadTransferException: ThreadPool worker terminated via exit()
int(49)
