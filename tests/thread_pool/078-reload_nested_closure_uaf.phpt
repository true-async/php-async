--TEST--
ThreadPool - reload() with a bootloader that spawns a nested closure coroutine (no cross-worker run_time_cache UAF, issue #176)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\delay;
use function Async\await;
use function Async\spawn;

// Bootloader spawns a nested closure as a long-lived coroutine on every worker.
// Before #176 the nested op_array was shared → cross-worker run_time_cache UAF.
$boot = function () {
    spawn(function () {
        static $ticks = 0;
        while (true) { $ticks++; delay(50); }
    });
};

$pool = new ThreadPool(4, 0, $boot);
delay(200);
for ($i = 0; $i < 6; $i++) {
    $pool->reload();
    delay(80);
}

var_dump(await($pool->submit(fn () => 42)) === 42);
$pool->close();

echo "done\n";
?>
--EXPECT--
bool(true)
done
