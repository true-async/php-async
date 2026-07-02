--TEST--
ThreadPool - overlapping reload() calls serialize and coalesce into one follow-up rotation
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
use function Async\await;

$file = sys_get_temp_dir() . '/tp_reload_coalesce_' . getmypid();
@unlink($file);

$boot = function () use ($file) {
    file_put_contents($file, 'x', FILE_APPEND | LOCK_EX);
};

$pool = new ThreadPool(1, 0, $boot);
delay(200);   // initial boot -> 1 mark

// Stretch rotation #1: the old worker is busy, its exit token comes late.
$f = $pool->submit(function () {
    usleep(800000);
    return 1;
});

delay(100);

$c1 = spawn(fn() => $pool->reload());   // becomes rotation #1
delay(100);                             // let it start and suspend in the drain
$c2 = spawn(fn() => $pool->reload());   // queues behind #1
$c3 = spawn(fn() => $pool->reload());   // queues behind #1

await($c1);
await($c2);
await($c3);
delay(300);   // let the last replacement finish booting

// 1 initial + rotation #1 + ONE coalesced follow-up = 3 boots, not 4.
$boots = strlen(@file_get_contents($file) ?: '');

var_dump($boots === 3);
var_dump(await($f) === 1);
var_dump(await($pool->submit(fn() => 9)) === 9);

$pool->close();
@unlink($file);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
done
