--TEST--
ThreadPool - respawnWorker starts a fresh thread that re-runs the bootloader
--FILE--
<?php

use Async\ThreadPool;
use function Async\delay;

$file = sys_get_temp_dir() . '/tp_respawn_' . getmypid();
@unlink($file);

// The bootloader records one mark per worker boot (across threads).
$boot = function () use ($file) {
    file_put_contents($file, 'x', FILE_APPEND | LOCK_EX);
};

$pool = new ThreadPool(1, 0, $boot);
delay(250);
$before = strlen(@file_get_contents($file) ?: '');

$ok    = $pool->respawnWorker(0);   // fresh thread in slot 0 -> bootloader runs again
delay(350);
$after = strlen(@file_get_contents($file) ?: '');

var_dump($ok);                       // respawn accepted
var_dump($before === 1);             // one boot before
var_dump($after === $before + 1);    // fresh worker re-ran the bootloader
var_dump($pool->respawnWorker(99));  // out-of-range slot rejected

$pool->close();
@unlink($file);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
done
