--TEST--
ThreadPool: a task nobody ever awaited keeps running
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\delay;

// The returned future is dropped on the spot. That is the fire-and-forget
// pattern submit() supports, and it must not read as a withdrawn awaiter.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    $pool->submit(function() {
        delay(300);
        echo "detached task reached its end\n";
        return 'done';
    });

    delay(1200);
    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
detached task reached its end
Done
