--TEST--
ThreadPool - reload() returns even when every replacement dies in its bootloader
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

$pool = new ThreadPool(2, 0, $boot);
delay(300);
$before = strlen(@file_get_contents($file) ?: '');

touch($flag);
$pool->reload();   // liveness: tokens come from the OLD cohort, not the dying replacements
echo "reload returned\n";

delay(300);
$after = strlen(@file_get_contents($file) ?: '');

var_dump($before === 2);
var_dump($after === 4);   // both replacements attempted their boot before dying

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
