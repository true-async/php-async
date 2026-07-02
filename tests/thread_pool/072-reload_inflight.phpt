--TEST--
ThreadPool - reload() waits for an in-flight task; the task survives the rotation
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

$pool = new ThreadPool(1);

$f = $pool->submit(function () {
    usleep(800000);
    return 'slow-done';
});

delay(150);   // the single worker is busy inside the task

$t0 = microtime(true);
$pool->reload();   // must suspend until the old worker finishes and exits
$elapsed = microtime(true) - $t0;

var_dump(await($f) === 'slow-done');   // in-flight task was not dropped
var_dump($elapsed >= 0.3);             // reload actually waited for the drain
var_dump(await($pool->submit(fn() => 42)) === 42);   // fresh worker serves

$pool->close();

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
done
