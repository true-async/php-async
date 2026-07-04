--TEST--
ThreadPool - submit() tasks that declare nested closures run per-worker without a shared run_time_cache UAF (issue #176)
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

$pool = new ThreadPool(6, 0, function () {});
delay(80);

// Each task declares its own nested closures across six workers + a reload.
// Fresh instances per task, so the static counter is always 1 → each returns 43.
$task = function () {
    $mul   = function ($x) { return $x * 2; };
    $inc   = fn ($y) => $y + 1;
    $count = function () { static $n = 0; return ++$n; };
    return $mul(20) + $inc(1) + $count();
};

$futures = [];
for ($i = 0; $i < 12; $i++) {
    $futures[] = $pool->submit($task);
}

$pool->reload();
delay(60);

for ($i = 0; $i < 12; $i++) {
    $futures[] = $pool->submit($task);
}

$ok = true;
foreach ($futures as $future) {
    $ok = $ok && (await($future) === 43);
}
var_dump($ok);

$pool->close();

echo "done\n";
?>
--EXPECT--
bool(true)
done
