--TEST--
ThreadPool - reload() rolls fresh workers that re-run the bootloader
--FILE--
<?php

use Async\ThreadPool;
use function Async\delay;
use function Async\await;

$file = sys_get_temp_dir() . '/tp_reload_' . getmypid();
@unlink($file);

// One mark per worker boot (across threads).
$boot = function () use ($file) {
    file_put_contents($file, 'x', FILE_APPEND | LOCK_EX);
};

$pool = new ThreadPool(3, 0, $boot);
delay(300);
$before = strlen(@file_get_contents($file) ?: '');

$pool->reload();   // suspends until the old cohort has drained
delay(300);
$after = strlen(@file_get_contents($file) ?: '');

var_dump($before === 3);            // three initial boots
var_dump($after === $before * 2);   // each worker replaced by a fresh boot
var_dump(await($pool->submit(fn() => 42)) === 42);   // pool still serves after reload

$pool->close();
@unlink($file);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
done
