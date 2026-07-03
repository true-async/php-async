--TEST--
ThreadPool - reload() returns even when every replacement dies in its bootloader
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\delay;

$file = sys_get_temp_dir() . '/tp_reload_bf_' . getmypid();
$flag = $file . '.flag';
@unlink($file);
@unlink($flag);

// Marks the boot, then dies iff the flag file exists (i.e. only replacements die).
$boot = function () use ($file, $flag) {
    file_put_contents($file, 'x', FILE_APPEND | LOCK_EX);
    if (file_exists($flag)) {
        exit(1);
    }
};

// Workers boot on their own OS threads, so the marks appear asynchronously.
// Wait for the expected count rather than a fixed delay — a loaded CI box can
// lag well past any constant, but the count only ever grows toward the target.
$marks     = static fn (): int => strlen(@file_get_contents($file) ?: '');
$waitMarks = static function (int $want) use ($marks): int {
    // Wall-clock bound, not an iteration count: under a saturated scheduler
    // delay() advances little real time, and the worker threads need real
    // time to be scheduled. Well within run-tests' own timeout.
    $deadline = microtime(true) + 30.0;
    while ($marks() < $want && microtime(true) < $deadline) {
        delay(10);
    }
    return $marks();
};

$pool = new ThreadPool(2, 0, $boot);
$before = $waitMarks(2);   // both original workers booted

touch($flag);
$pool->reload();   // liveness: tokens come from the OLD cohort, not the dying replacements
echo "reload returned\n";

$after = $waitMarks(4);    // both replacements attempted their boot before dying

var_dump($before === 2);
var_dump($after === 4);

$pool->close();
@unlink($file);
@unlink($flag);

echo "done\n";
?>
--EXPECT--
reload returned
bool(true)
bool(true)
done
