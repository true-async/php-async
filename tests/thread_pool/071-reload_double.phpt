--TEST--
ThreadPool - two sequential reload() calls each roll a full fresh cohort
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

$file = sys_get_temp_dir() . '/tp_reload_double_' . getmypid();
@unlink($file);

// One mark per worker boot (across threads).
$boot = function () use ($file) {
    file_put_contents($file, 'x', FILE_APPEND | LOCK_EX);
};

$pool = new ThreadPool(3, 0, $boot);
delay(300);
$b0 = strlen(@file_get_contents($file) ?: '');

$pool->reload();
delay(300);
$b1 = strlen(@file_get_contents($file) ?: '');

$pool->reload();
delay(300);
$b2 = strlen(@file_get_contents($file) ?: '');

var_dump($b0 === 3);        // initial cohort
var_dump($b1 === 6);        // first rotation replaced all three
var_dump($b2 === 9);        // second rotation replaced them again
var_dump(await($pool->submit(fn() => 7)) === 7);   // pool still serves

$pool->close();
@unlink($file);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
done
