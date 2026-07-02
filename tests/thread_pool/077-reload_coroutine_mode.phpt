--TEST--
ThreadPool - reload() in coroutine mode: in-flight task coroutines drain, fresh cohort boots
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

$file = sys_get_temp_dir() . '/tp_reload_coro_' . getmypid();
@unlink($file);

$boot = function () use ($file) {
    file_put_contents($file, 'x', FILE_APPEND | LOCK_EX);
};

$pool = new ThreadPool(workers: 2, bootloader: $boot, coroutine: true, concurrency: 2);
delay(300);
$before = strlen(@file_get_contents($file) ?: '');

// Both workers hold a task coroutine suspended in delay() when the rotation
// starts: the old worker's exit token comes only after AFTER_MAIN drains it.
$f1 = $pool->submit(static function () { delay(400); return 'a'; });
$f2 = $pool->submit(static function () { delay(400); return 'b'; });
delay(100);

$pool->reload();

var_dump(await($f1) === 'a');   // in-flight coroutine tasks survived the rotation
var_dump(await($f2) === 'b');

delay(300);
$after = strlen(@file_get_contents($file) ?: '');

var_dump($before === 2);
var_dump($after === 4);         // both replacements booted
var_dump(await($pool->submit(static fn() => 42)) === 42);

$pool->close();
@unlink($file);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
done
